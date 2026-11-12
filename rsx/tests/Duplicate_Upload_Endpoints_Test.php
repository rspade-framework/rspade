<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_Duplicate_Upload;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Api\V1\Tasks_Api_Controller;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Lib\Attachments\Record_Attachment_List;
use Rsx\Models\Client_Model;
use Rsx\Models\Portal_Request_Thread_Model;
use Rsx\Models\Task_Model;

/**
 * The template's claim endpoints ask the framework's duplicate-upload question
 * (Rsx_Duplicate_Upload) before filing a file whose bytes the record already holds.
 *
 *   - a task's attachments: the batch asks about each duplicate in turn and attaches nothing
 *     until every question is answered; Skip leaves that file unclaimed, Upload Again files it;
 *   - a client's documents: Skip files nothing and answers skipped: true;
 *   - a staff thread reply carrying only skipped files posts no message;
 *   - the external API refuses a duplicate with 409 duplicate_upload unless the
 *     duplicate_upload parameter answers it.
 *
 * Endpoints are invoked directly at the controller layer: a question comes back as the
 * Error_Response the dispatcher would send, and the answers are passed in _answers exactly
 * as Rsx_Form.call_answering() resends them.
 */
class Duplicate_Upload_Endpoints_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __task(): Task_Model
    {
        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Duplicate Upload Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return $task;
    }

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Duplicate Upload Client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    /** An unclaimed upload of $bytes, as Ajax.upload() leaves it. */
    private static function __upload(string $bytes, string $name): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string($bytes, $name, ['site_id' => self::SITE_ID]);
    }

    private static function __bytes(): string
    {
        return 'duplicate-endpoint-' . uniqid('', true);
    }

    private static function __question_key($response): ?string
    {
        if (!$response instanceof Error_Response || $response->get_error_code() !== Ajax::ERROR_QUESTION) {
            return null;
        }

        return $response->get_metadata()['key'];
    }

    private static function __add_to_task(Task_Model $task, array $keys, array $answers = [])
    {
        $params = ['id' => $task->id, 'keys' => $keys];
        if ($answers !== []) {
            $params['_answers'] = $answers;
        }

        return Frontend_Tasks_Controller::attachments_add(new Request(), $params);
    }

    // --- a task's attachment list ----------------------------------------------------------------

    public static function test_a_task_batch_asks_about_each_duplicate_and_writes_nothing_until_answered()
    {
        $task = static::__task();
        $bytes_a = static::__bytes();
        $bytes_b = static::__bytes();
        static::__add_to_task($task, [static::__upload($bytes_a, 'a.txt')->key, static::__upload($bytes_b, 'b.txt')->key]);

        $new = static::__upload(static::__bytes(), 'new.txt');
        $again_a = static::__upload($bytes_a, 'a renamed.txt');
        $again_b = static::__upload($bytes_b, 'b.txt');
        $keys = [$new->key, $again_a->key, $again_b->key];

        $first = static::__add_to_task($task, $keys);
        static::__assert_equals(Rsx_Duplicate_Upload::question_key($again_a), static::__question_key($first), 'asks about the first duplicate');
        static::__assert_count(2, Record_Attachment_List::rows($task), 'nothing attached while a question is pending');

        $answers = [Rsx_Duplicate_Upload::question_key($again_a) => false];
        $second = static::__add_to_task($task, $keys, $answers);
        static::__assert_equals(Rsx_Duplicate_Upload::question_key($again_b), static::__question_key($second), 'then about the next');
        static::__assert_count(2, Record_Attachment_List::rows($task), 'still nothing attached');

        $answers[Rsx_Duplicate_Upload::question_key($again_b)] = true;
        $done = static::__add_to_task($task, $keys, $answers);
        static::__assert_true(is_array($done), 'answered in full, the claim succeeds');

        $ids = array_column(Record_Attachment_List::rows($task), 'file_attachment_id');
        static::__assert_count(4, $ids, 'the two originals, the new file, and the one uploaded again');
        static::__assert_true(in_array((int) $new->id, $ids, true));
        static::__assert_true(in_array((int) $again_b->id, $ids, true), 'Upload Again files it');
        static::__assert_false(in_array((int) $again_a->id, $ids, true), 'Skip This File does not');
        static::__assert_null(File_Attachment_Model::find($again_a->id)->fileable_type, 'the skipped upload stays unclaimed for the sweep');
    }

    // --- a client's documents --------------------------------------------------------------------

    public static function test_a_skipped_client_document_files_nothing()
    {
        $client = static::__client();
        $bytes = static::__bytes();
        Frontend_Clients_Controller::documents_add(new Request(), ['client_id' => $client->id, 'key' => static::__upload($bytes, 'contract.pdf')->key]);

        $again = static::__upload($bytes, 'contract.pdf');
        $params = ['client_id' => $client->id, 'key' => $again->key];

        static::__assert_equals(
            Rsx_Duplicate_Upload::question_key($again),
            static::__question_key(Frontend_Clients_Controller::documents_add(new Request(), $params))
        );

        $params['_answers'] = [Rsx_Duplicate_Upload::question_key($again) => false];
        $result = Frontend_Clients_Controller::documents_add(new Request(), $params);

        static::__assert_true($result['skipped']);
        static::__assert_null(File_Attachment_Model::find($again->id)->fileable_type);
    }

    // --- a staff thread reply --------------------------------------------------------------------

    public static function test_a_thread_reply_carrying_only_a_skipped_file_posts_nothing()
    {
        $client = static::__client();
        $created = Frontend_Clients_Controller::request_thread_create(new Request(), [
            'client_id' => $client->id,
            'title' => 'Duplicate upload thread',
            'body' => 'Opening message.',
        ]);
        $thread = Portal_Request_Thread_Model::find($created['thread_id']);
        $bytes = static::__bytes();

        Frontend_Clients_Controller::request_thread_reply(new Request(), [
            'thread_id' => $thread->id,
            'body' => '',
            'attachment_keys' => [static::__upload($bytes, 'scan.pdf')->key],
        ]);
        $messages_before = count($thread->messages());

        $again = static::__upload($bytes, 'scan.pdf');
        $params = ['thread_id' => $thread->id, 'body' => '', 'attachment_keys' => [$again->key]];

        static::__assert_equals(
            Rsx_Duplicate_Upload::question_key($again),
            static::__question_key(Frontend_Clients_Controller::request_thread_reply(new Request(), $params))
        );
        static::__assert_equals($messages_before, count($thread->messages()), 'a question posts nothing');

        $params['_answers'] = [Rsx_Duplicate_Upload::question_key($again) => false];
        $result = Frontend_Clients_Controller::request_thread_reply(new Request(), $params);

        static::__assert_null($result['message_id'], 'nothing left to send');
        static::__assert_equals($messages_before, count($thread->messages()));
    }

    // --- the external API ------------------------------------------------------------------------

    public static function test_the_api_refuses_a_duplicate_unless_the_parameter_answers_it()
    {
        $task = static::__task();
        $bytes = static::__bytes();
        $original = static::__upload($bytes, 'spec.txt');
        Tasks_Api_Controller::attachments_attach(new Request(), ['id' => $task->id, 'key' => $original->key]);

        $again = static::__upload($bytes, 'spec copy.txt');

        $refused = Tasks_Api_Controller::attachments_attach(new Request(), ['id' => $task->id, 'key' => $again->key]);
        static::__assert_equals(409, $refused->getStatusCode());
        static::__assert_equals('duplicate_upload', $refused->getData(true)['error']['code']);

        $skipped = Tasks_Api_Controller::attachments_attach(new Request(), ['id' => $task->id, 'key' => $again->key, 'duplicate_upload' => false]);
        static::__assert_true($skipped['skipped']);
        static::__assert_equals((int) $original->id, (int) $skipped['existing']['attachment_id']);
        static::__assert_null(File_Attachment_Model::find($again->id)->fileable_type);

        $filed = Tasks_Api_Controller::attachments_attach(new Request(), ['id' => $task->id, 'key' => $again->key, 'duplicate_upload' => true]);
        static::__assert_equals(201, $filed->getStatusCode());
        static::__assert_equals((int) $task->id, (int) File_Attachment_Model::find($again->id)->fileable_id);
    }
}
