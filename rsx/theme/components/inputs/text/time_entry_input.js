/**
 * Time_Entry_Input
 *
 * An hours field that also accepts H:MM. The whole behaviour is the framework's jQuery
 * filter `$(input).rsx_numeric({time: true})` - digits plus a colon while typing, the
 * colon converted to decimal hours the moment the value leaves the user's hands - so this
 * class is the filter's time configuration and nothing else. `_get_value()` /
 * `_set_value()` are `Text_Input`'s.
 *
 * Arguments: `Text_Input`'s. There is no $decimals - hours are two decimal places by
 * definition, and the mode forces it.
 *
 * Usage:
 *   <Form_Field $label="Hours"><Time_Entry_Input $name="hours" /></Form_Field>
 *
 * Behavior:
 * - Type "1:30", leave the field -> displays "1.5"
 * - Type ":30" -> "0.5";  ":90" -> "1.5";  "2:15" -> "2.25";  "1:20" -> "1.33"
 * - Type "1:63" -> "2.05" - minutes at or past 60 roll into hours
 * - Type "1.5" -> "1.5" - a decimal is already decimal hours and is left alone
 * - val() is ALWAYS decimal hours, never a colon; val("2:15") displays "2.25"
 */
class Time_Entry_Input extends Text_Input {
    on_ready() {
        // The filter goes on FIRST, so the buffered value the parent writes when it marks
        // the input ready already passes through it: what the box reads back at readiness
        // is what it will read back afterwards (the form's baseline - rsx:man form_input,
        // CHANGED).
        this.$sid('input').rsx_numeric({
            time: true,
            commas: false,
        });

        super.on_ready();
    }
}
