/**
 * Dev_Spa_Layout - SPA layout for dev tools
 * Mirrors the blade-based dev layout but for SPA navigation
 */
class Dev_Spa_Layout extends Spa_Layout {
    on_create() {
        console.log('[Dev_Spa_Layout] Layout created');
    }

    /** The sidebar's sections, read by the template. */
    nav_sections() {
        return [
            {
                title: 'Dev Tools',
                items: [
                    {
                        label: 'Home',
                        icon: 'bi-house-door',
                        href: Rsx.Route('Dev_Index_Controller')
                    },
                    {
                        label: 'SPA Test',
                        icon: 'bi-router',
                        href: Rsx.Route('Dev_Spa_Action')
                    },
                    {
                        label: 'Modals',
                        icon: 'bi-window',
                        href: Rsx.Route('Dev_Modals_Controller')
                    },
                    {
                        label: 'Attachments',
                        icon: 'bi-paperclip',
                        href: Rsx.Route('Dev_Attachments_Controller::#index')
                    },
                    {
                        label: 'Flash Alerts',
                        icon: 'bi-bell',
                        href: Rsx.Route('Dev_Flash_Controller')
                    },
                    {
                        label: 'ORM',
                        icon: 'bi-database',
                        href: Rsx.Route('Dev_Orm_Action')
                    },
                    {
                        label: 'Document Preview',
                        icon: 'bi-file-earmark-text',
                        href: Rsx.Route('Dev_Document_Preview_Action')
                    },
                    {
                        label: 'Attachment Thumbnail',
                        icon: 'bi-image',
                        href: Rsx.Route('Dev_Attachment_Thumbnail_Action')
                    }
                ]
            }
        ];
    }
}
