/**
 * Raw_Text - the browser registration for the plain-text column type.
 *
 * The PHP class of the same name owns the encoding. All that lives here is which
 * component prints the value and which one edits it.
 */
class Raw_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Raw_Text_Display';

    static EDITOR = 'Raw_Text_Input';

    static EDITOR_ARGS = { rows: 4 };
}
