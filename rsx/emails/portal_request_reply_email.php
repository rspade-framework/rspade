<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Portal_Request_Reply_Email - tells the staff owner of a request that the client
 * replied.
 *
 * The reply text is a SNIPPET, deliberately: the whole conversation lives behind the
 * link, where it is gated.
 *
 * TRANSACTIONAL: it goes to the staff member who owns the thread, about their own work.
 */
class Portal_Request_Reply_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    public function __construct(
        public string $staff_name,
        public string $client_name,
        public string $replier_name,
        public string $thread_title,
        public string $message,
        public string $view_url
    ) {
    }

    public function subject(): string
    {
        return 'Client reply on "' . $this->thread_title . '"';
    }

    public function data(): array
    {
        return [
            'staff_name' => $this->staff_name,
            'client_name' => $this->client_name,
            'replier_name' => $this->replier_name,
            'thread_title' => $this->thread_title,
            'message' => $this->message,
            'view_url' => $this->view_url,
        ];
    }

    public static function sample(): static
    {
        return new static(
            'Ada Lovelace',
            'Northwind Trading',
            'client@example.com',
            'Missing invoice for March',
            'Attaching the invoice you asked for - let me know if you need anything else.',
            rsx_absolute_url(Rsx::Route('Clients_Request_Thread_Action', ['id' => 1, 'thread_id' => 1])),
        );
    }
}
