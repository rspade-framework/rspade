<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Embed_References;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\Lib\TextTypes\RichText\Rich_Text;
use Rsx\Models\Project_Model;

/**
 * Files embedded in rich text ({{#file:<attachment id>}}): the record that saves the text owns
 * the file, the unclaimed-upload sweep never takes it, and nobody but the uploader can claim
 * or see a pending upload by typing its id.
 *
 * Every case is a way an embedded file could be LOST or LEAKED without an error anywhere:
 * an unowned embed is swept a day later and renders "File not found"; a claim that accepted
 * any id would let one user take another's pending upload.
 */
class Inline_File_Embed_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const UPLOADER_USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::UPLOADER_USER_ID);
    }

    /** An upload as /_upload leaves it: unclaimed, stamped with the acting user. */
    private static function __upload(string $name = 'embedded.pdf'): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string('embed-' . uniqid('', true), $name, ['site_id' => self::SITE_ID]);
    }

    /** A project whose description embeds the given attachments. */
    private static function __project_embedding(array $attachment_ids): Project_Model
    {
        $project = new Project_Model();
        $project->name = 'Embed ' . str_replace('.', '', uniqid('', true));
        $project->client_id = 1;
        $project->status = Project_Model::STATUS_ACTIVE;
        $project->priority = Project_Model::PRIORITY_MEDIUM;
        $project->description = static::__text_embedding($attachment_ids);
        $project->save();

        return $project;
    }

    private static function __text_embedding(array $attachment_ids): Rich_Text
    {
        $tokens = implode(' ', array_map(fn ($id) => '{{#file:' . $id . '}}', $attachment_ids));

        return Rich_Text::from_untrusted_encoded('<p>See ' . $tokens . ' here.</p>');
    }

    /** A second staff user on the same site, to act as somebody who did not upload. */
    private static function __other_user(): User_Model
    {
        $email = 'embed-other-' . uniqid() . '@test.example';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = 'not-a-login-path';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Other';
        $user->last_name = 'Editor';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    private static function __fresh(File_Attachment_Model $attachment): File_Attachment_Model
    {
        return File_Attachment_Model::withTrashed()->find($attachment->id);
    }

    public static function test_saving_text_that_embeds_an_upload_makes_the_record_own_it()
    {
        $upload = static::__upload();
        $project = static::__project_embedding([$upload->id]);

        $upload = static::__fresh($upload);
        static::__assert_equals('Project_Model', $upload->fileable_type, 'the record that saved the text owns the file');
        static::__assert_equals((int) $project->id, (int) $upload->fileable_id, 'that record');
        static::__assert_equals(File_Attachment_Model::CATEGORY_INLINE_EMBED, $upload->fileable_category, 'in the reserved embed category');
        static::__assert_true(File_Embed_References::is_referenced((int) $upload->id), 'with a reference row');
        static::__assert_equals(
            [['owner_type' => 'Project_Model', 'owner_id' => (int) $project->id, 'column_name' => 'description']],
            File_Embed_References::owners_of((int) $upload->id),
            'naming the record and column'
        );
    }

    public static function test_the_unclaimed_sweep_leaves_an_embedded_file_alone()
    {
        // Embedded by somebody else's save, so the claim is refused and the file stays
        // unclaimed - the case the sweep's own exemption exists for.
        $embedded = static::__upload('embedded.pdf');
        $abandoned = static::__upload('abandoned.pdf');

        $other = static::__other_user();
        static::__acting_as_user((int) $other->id);
        static::__project_embedding([$embedded->id]);
        static::__acting_as_user(self::UPLOADER_USER_ID);

        $long_ago = date('Y-m-d H:i:s', time() - 7 * 86400);
        DB::table('_file_attachments')->whereIn('id', [$embedded->id, $abandoned->id])->update(['created_at' => $long_ago]);

        Task::internal('File_Disposal_Service', 'sweep_unclaimed_uploads');

        static::__assert_null(static::__fresh($embedded)->fileable_type, 'still unclaimed');
        static::__assert_false(static::__fresh($embedded)->trashed(), 'yet not swept: a record embeds it');
        static::__assert_true(static::__fresh($abandoned)->trashed(), 'an upload nothing embeds is swept as before');
    }

    public static function test_another_user_cannot_claim_a_pending_upload_by_typing_its_id()
    {
        $upload = static::__upload();

        $other = static::__other_user();
        static::__acting_as_user((int) $other->id);
        $project = static::__project_embedding([$upload->id]);

        $upload = static::__fresh($upload);
        static::__assert_null($upload->fileable_type, 'the file stays unclaimed');
        static::__assert_false($upload->claim_for_embed($project), 'and a direct claim is refused too');

        static::__acting_as_user(self::UPLOADER_USER_ID);
        static::__assert_true(static::__fresh($upload)->claim_for_embed($project), 'the uploader can still claim it');
    }

    public static function test_removing_the_token_keeps_the_file_and_its_owner_and_drops_the_reference()
    {
        $upload = static::__upload();
        $project = static::__project_embedding([$upload->id]);

        $project->description = Rich_Text::from_untrusted_encoded('<p>No file any more.</p>');
        $project->save();

        $upload = static::__fresh($upload);
        static::__assert_false($upload->trashed(), 'the file is kept - a restored revision still shows it');
        static::__assert_equals((int) $project->id, (int) $upload->fileable_id, 'and so is its owner');
        static::__assert_false(File_Embed_References::is_referenced((int) $upload->id), 'only the reference goes');
    }

    public static function test_the_same_token_in_a_second_record_adds_a_reference_and_never_moves_ownership()
    {
        $upload = static::__upload();
        $first = static::__project_embedding([$upload->id]);
        $second = static::__project_embedding([$upload->id]);

        static::__assert_equals((int) $first->id, (int) static::__fresh($upload)->fileable_id, 'the first record keeps it');
        static::__assert_equals(2, count(File_Embed_References::owners_of((int) $upload->id)), 'both records reference it');
        static::__assert_equals((int) $second->id, (int) File_Embed_References::owners_of((int) $upload->id)[1]['owner_id'], 'the second is recorded');
    }

    public static function test_an_embed_the_viewer_may_not_see_is_left_out_of_the_cards()
    {
        $upload = static::__upload();

        $own = Ajax::internal('Inline_File_Controller', 'resolve_files', ['ids' => [$upload->id]]);
        static::__assert_true(isset(((array) $own['files'])[$upload->id]), 'the uploader sees their pending upload');

        $other = static::__other_user();
        static::__acting_as_user((int) $other->id);
        $theirs = Ajax::internal('Inline_File_Controller', 'resolve_files', ['ids' => [$upload->id, 999999999]]);
        static::__assert_equals([], array_keys((array) $theirs['files']), 'nobody else does, and a missing id is left out too: both render "File not found"');
    }

    public static function test_a_save_with_revisions_suppressed_still_claims_its_files()
    {
        $upload = static::__upload();
        $project = Revision::without(fn () => static::__project_embedding([$upload->id]));

        static::__assert_equals((int) $project->id, (int) static::__fresh($upload)->fileable_id, 'the claim is the type\'s integrity, not an optional effect');
    }

    public static function test_a_query_builder_update_runs_the_claim_per_record()
    {
        $project = static::__project_embedding([]);
        $upload = static::__upload();

        Project_Model::where('id', $project->id)->update(['description' => static::__text_embedding([$upload->id])]);

        static::__assert_equals((int) $project->id, (int) static::__fresh($upload)->fileable_id, 'a bulk update takes the per-record path for a hooked text type');
    }

    public static function test_a_body_holding_only_a_file_is_not_empty_and_has_no_plain_text()
    {
        $value = Rich_Text::from_untrusted_encoded('<p>{{#file:12}}</p>');

        static::__assert_false($value->is_empty(), 'an embed is content');
        static::__assert_equals('', $value->to_plain_text(), 'but not words');
        static::__assert_equals([12], $value->file_ids(), 'the id is read back');
    }
}
