<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Tasks\Frontend_Tasks_Controller;
use Rsx\Lib\Attachments\Record_Attachment_List;
use Rsx\Models\Task_Model;

/**
 * A task's file attachments: Frontend_Tasks_Controller::task_attachments() and
 * attachments_add(), over Record_Attachment_List.
 *
 *   - uploaded keys are claimed onto the task and listed oldest first, each row carrying
 *     what the Attachments section and its preview dialog display;
 *   - a claim is all-or-nothing: one unclaimable key attaches none of the batch;
 *   - a key already attached to another task cannot be claimed again;
 *   - one task's list never shows another task's files.
 *
 * Endpoints are invoked directly at the controller layer; a successful call returns an
 * array and a refusal returns the error response instead.
 */
class Task_Record_Attachments_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __task(): Task_Model
    {
        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Attachments Test Task ' . uniqid();
        $task->status = Task_Model::STATUS_PENDING;
        $task->priority = Task_Model::PRIORITY_MEDIUM;
        $task->save();

        return $task;
    }

    /** An unclaimed upload, as Ajax.upload() leaves it. */
    private static function __upload(string $name): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'task-attachment-' . uniqid(),
            $name,
            ['site_id' => self::SITE_ID]
        );
    }

    private static function __add(Task_Model $task, array $keys)
    {
        return Frontend_Tasks_Controller::attachments_add(new Request(), ['id' => $task->id, 'keys' => $keys]);
    }

    private static function __list(Task_Model $task): array
    {
        return Frontend_Tasks_Controller::task_attachments(new Request(), ['id' => $task->id])['attachments'];
    }

    public static function test_uploaded_files_are_attached_and_listed_oldest_first()
    {
        $task = static::__task();
        $first = static::__upload('brief.pdf');
        $second = static::__upload('photo.png');

        $result = static::__add($task, [$second->key, $first->key]);

        static::__assert_true(is_array($result), 'the claim succeeds');
        static::__assert_count(2, $result['attachments'], 'and answers the new list');

        $rows = static::__list($task);

        static::__assert_equals(
            [(int) $first->id, (int) $second->id],
            array_column($rows, 'file_attachment_id'),
            'oldest first, whatever order the keys were posted in'
        );
    }

    public static function test_a_row_carries_what_the_section_and_the_preview_display()
    {
        $task = static::__task();
        $file = static::__upload('brief.pdf');
        static::__add($task, [$file->key]);

        $row = static::__list($task)[0];

        static::__assert_equals(
            ['file_attachment_id', 'file_name', 'file_type_label', 'size', 'created_at', 'created_by_name', 'download_url'],
            array_keys($row)
        );
        static::__assert_equals('brief.pdf', $row['file_name']);
        static::__assert_equals('PDF Document', $row['file_type_label']);
        static::__assert_greater_than(0, $row['size']);
        static::__assert_contains('/_download/' . $file->key, $row['download_url']);
    }

    public static function test_files_are_filed_under_the_shared_category()
    {
        $task = static::__task();
        $file = static::__upload('brief.pdf');
        static::__add($task, [$file->key]);

        static::__assert_not_null(
            $task->find_attachment($file->id, Record_Attachment_List::CATEGORY),
            'the file belongs to this task, in the attachments category'
        );
    }

    public static function test_one_unclaimable_key_attaches_none_of_the_batch()
    {
        $task = static::__task();
        $good = static::__upload('brief.pdf');

        $result = static::__add($task, [$good->key, 'no-such-key']);

        static::__assert_false(is_array($result), 'the claim is refused');
        static::__assert_count(0, static::__list($task), 'and the good file was not attached either');
    }

    public static function test_a_key_attached_to_another_task_cannot_be_claimed_again()
    {
        $owner = static::__task();
        $other = static::__task();
        $file = static::__upload('brief.pdf');
        static::__add($owner, [$file->key]);

        $result = static::__add($other, [$file->key]);

        static::__assert_false(is_array($result), 'the second claim is refused');
        static::__assert_count(1, static::__list($owner), 'the file stays where it was');
        static::__assert_count(0, static::__list($other));
    }

    public static function test_an_empty_or_missing_key_list_is_refused()
    {
        $task = static::__task();

        static::__assert_false(is_array(static::__add($task, [])), 'no keys');
        static::__assert_false(
            is_array(Frontend_Tasks_Controller::attachments_add(new Request(), ['id' => $task->id])),
            'no keys parameter'
        );
    }
}
