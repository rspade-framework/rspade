<?php
/**
 * Phone_Libphonenumber_Bundle - npm Asset Bundle for phone number formatting
 *
 * Exposes Google's libphonenumber JS port (google-libphonenumber) as a global
 * `libphonenumber` for use by Formatters.phone() (rsx/lib/formatters.js) and the
 * Phone_Text_Input component (co-located in this directory).
 *
 * This is the JS counterpart of the PHP giggsey/libphonenumber-for-php dependency
 * used by Rsx\Lib\Formatters::phone() - both share Google's metadata so PHP and JS
 * produce identical international/national formatting.
 *
 * Co-located with phone_text_input.js so it is auto-discovered by every Module
 * Bundle that ships the component: Frontend_Bundle / Portal_Bundle / Root_Bundle /
 * Dev_Bundle (scan `rsx/theme`) and Login_Bundle (scans `rsx/theme/components`).
 *
 * The global is a CommonJS default export (module.exports = phonenumbers namespace),
 * so the default-import form below yields the object carrying PhoneNumberUtil and
 * PhoneNumberFormat.
 */

namespace Rsx\Theme\Components\Inputs\Text;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

class Phone_Libphonenumber_Bundle extends Rsx_Asset_Bundle_Abstract
{
    /**
     * Define the bundle configuration
     *
     * @return array Bundle configuration
     */
    public static function define(): array
    {
        return [
            'npm' => [
                'libphonenumber' => "import libphonenumber from 'google-libphonenumber'",
            ],
        ];
    }
}
