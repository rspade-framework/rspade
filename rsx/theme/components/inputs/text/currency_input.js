/**
 * Currency_Input
 *
 * A Text_Input whose box is a numeric field. The whole behaviour is the framework's
 * jQuery filter `$(input).rsx_numeric()` - digits only, thousands separators and the
 * currency symbol written inline as the user types, and `.val()` answering the raw
 * number both ways - so this class is the filter's currency configuration and nothing
 * else. `_get_value()` / `_set_value()` are `Text_Input`'s.
 *
 * Arguments:
 * - $decimals - decimal places, 0 for whole units (default: 2)
 * - $prefix - the display-only symbol (default: "$")
 * - $commas - thousands separators (default: true)
 *
 * Usage:
 *   <Currency_Input $name="amount" />
 *   <Currency_Input $name="headcount" $decimals=0 $prefix="" />
 *   <Currency_Input $name="amount" $prefix="EUR " />
 *
 * Behavior:
 * - Type "1234567" -> displays "$1,234,567", val() returns "1234567"
 * - Type "1234567.89" -> displays "$1,234,567.89", val() returns "1234567.89"
 * - val("9876543.21") -> displays "$9,876,543.21"
 */
class Currency_Input extends Text_Input {
    on_ready() {
        // The parent marks the input ready, which writes any buffered value into the
        // box; the filter then formats whatever is there.
        super.on_ready();

        this.$sid('input').rsx_numeric({
            decimals: this.args.decimals === undefined ? 2 : int(this.args.decimals),
            commas: this.args.commas === undefined ? true : this.args.commas === true,
            prefix: this.args.prefix === undefined ? '$' : str(this.args.prefix),
        });
    }
}
