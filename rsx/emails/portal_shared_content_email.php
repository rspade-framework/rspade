<?php

namespace Rsx\Emails;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * Portal_Shared_Content_Email - tells a portal contact that a document is waiting.
 *
 * Note what is NOT here: the document. The email carries a link into the portal, where
 * the recipient's own access rules decide what they may see.
 *
 * TRANSACTIONAL: somebody deliberately shared this with this person.
 */
class Portal_Shared_Content_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    /**
     * @param string $shared_by_name Who shared it - the client, in the subject line.
     * @param string $message The sharer's note, or a description of the document.
     * @param string $view_url The recipient's share link (Shared_Item_Model::get_share_url()).
     * @param string|null $expires_at ISO datetime the link stops working, if it does.
     */
    public function __construct(
        public string $shared_by_name,
        public string $message,
        public string $view_url,
        public ?string $expires_at = null
    ) {
    }

    public function subject(): string
    {
        return $this->shared_by_name . ' shared a document with you';
    }

    public function data(): array
    {
        return [
            'shared_by_name' => $this->shared_by_name,
            'app_name' => config('rsx.name', 'RSpade'),
            'message' => $this->message,
            'view_url' => $this->view_url,
            'expires_at' => $this->expires_at,
        ];
    }

    /**
     * A fixed illustrative portal link: sample() reads no database, and the portal's
     * address is per site (a site may have none) - rsx/emails/CLAUDE.md.
     */
    public static function sample(): static
    {
        return new static(
            'Northwind Trading',
            'Document: Q3 statement.pdf',
            'https://portal.example.com/shared/sample-link',
        );
    }
}
