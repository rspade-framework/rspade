/**
 * Revision_History - the change timeline of one record.
 *
 * Loads one PAGE of transactions (newest first) from Frontend_Revisions_Controller and
 * renders them grouped by transaction. Paging is a cursor: "Older changes" moves
 * this.args.before_id and reloads, which is the framework's own "args changed -> reload()"
 * pattern rather than an accumulating buffer the component would have to keep coherent.
 *
 * Value formatting lives here rather than on the server: the endpoint ships stored values
 * under their real field names (no aliasing, no server-side formatting), and the browser
 * already holds the model's vocabulary - the generated stub's field__enum_labels() and the
 * Rsx_Time / Rsx_Date formatters.
 */
class Revision_History extends Component {

    /** Characters of a value shown before the "Show more" toggle appears. */
    static TRUNCATE_AT = 160;

    /**
     * A MySQL datetime as it comes out of a raw column: no zone marker. The database
     * stores UTC, so the Z is a statement of fact, not a guess - and without it the
     * browser would read the string as local time and print the wrong moment.
     */
    static MYSQL_DATETIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

    on_create() {
        this.data.transactions = [];
        this.data.has_more = false;
        this.data.next_before_id = null;
        this.data.error_message = null;
        this.data.loading = true;

        // Which values the reader expanded, keyed by revision:field:side. UI state, so it
        // survives nothing but this render cycle - which is correct, a reload is a new page.
        this.state.expanded = {};

        // Live history: a write to the subject record is exactly what adds a row here.
        // The subject's model class is resolved from its name because this component is
        // generic - it serves every type the endpoint publishes. No existence guard: a
        // record_type whose model is not in this bundle is a wiring bug and must fail loud.
        // __REALTIME is a genuine optional capability (the stub emits it only for a model
        // that publishes), so it is tested with `in` - subscribe() throws on a model that
        // does not emit, and a history that simply never live-updates is the right answer
        // for one that does not.
        const model_class = Manifest.get_class_by_name(this.args.record_type);

        if ('__REALTIME' in model_class) {
            this.subscribe(model_class, this.args.record_id, () => this.refresh());
        }
    }

    async on_load() {
        try {
            const result = await Frontend_Revisions_Controller.history({
                record_type: this.args.record_type,
                record_id: this.args.record_id,
                before_id: this.args.before_id || 0,
            });

            this.data.transactions = result.transactions;
            this.data.has_more = result.has_more;
            this.data.next_before_id = result.next_before_id;
            this.data.error_message = null;
        } catch (e) {
            this.data.error_message = e.message;
        }

        this.data.loading = false;
    }

    on_render() {
        // Rebound on every render (the buttons are recreated), namespaced and idempotent.
        this.$.off('click.revision_history').on('click.revision_history', '.Revision_History__toggle', (e) => {
            const $button = $(e.currentTarget);
            const key = $button.attr('data-revision-value');

            this.state.expanded[key] = !this.state.expanded[key];
            this.render();
        });
    }

    on_ready() {
        if (this.$sid('older').exists()) {
            this.$sid('older').click(() => {
                this.args.before_id = this.data.next_before_id;
                this.reload();
            });
        }

        if (this.$sid('latest').exists()) {
            this.$sid('latest').click(() => {
                this.args.before_id = null;
                this.reload();
            });
        }
    }

    // =========================================================================
    // DISPLAY HELPERS (called from the template)
    // =========================================================================

    /**
     * One stored value as the reader should see it.
     *
     * NULL AND EMPTY STRING ARE DIFFERENT FACTS and this returns them differently:
     * null (or an absent value) comes back as null, which the template paints as a
     * literal "(null)", and an empty string comes back as '', which the template paints
     * as nothing at all. A history that rendered both the same would say a column was
     * cleared when it was actually set to a value the database distinguishes - the one
     * question a change timeline exists to answer.
     *
     * @param {string} record_type The model the value belongs to
     * @param {string} field The column name
     * @param {*} value The stored value
     * @returns {string|null} null for a null value; '' for an empty string
     */
    format_value(record_type, field, value) {
        if (value === null || value === undefined) {
            return null;
        }

        if (value === '') {
            return '';
        }

        const enum_label = this.enum_label(record_type, field, value);
        if (enum_label !== null) {
            return enum_label;
        }

        // Column-name conventions the framework enforces (rsx:man model): _at is a
        // timestamp, _date is a calendar date, is_ is a boolean.
        if (field.endsWith('_at')) {
            const normalized = this.normalize_datetime(value);
            if (Rsx_Time.is_datetime(normalized)) {
                return Rsx_Time.format_datetime(normalized);
            }
        }

        if (field.endsWith('_date') && Rsx_Date.is_date(value)) {
            return Rsx_Date.format(value);
        }

        if (field.startsWith('is_')) {
            return int(value) ? 'Yes' : 'No';
        }

        return str(value);
    }

    /**
     * The enum label for a value, or null when the field is not an enum on that model.
     */
    enum_label(record_type, field, value) {
        const model_class = Manifest.get_class_by_name(record_type);

        if (!model_class || typeof model_class[field + '__enum_labels'] !== 'function') {
            return null;
        }

        const labels = model_class[field + '__enum_labels']();

        return labels[value] ?? null;
    }

    /**
     * A raw MySQL datetime turned into the ISO-UTC form Rsx_Time reads; anything else is
     * returned untouched.
     */
    normalize_datetime(value) {
        if (typeof value === 'string' && Revision_History.MYSQL_DATETIME.test(value)) {
            return value.replace(' ', 'T') + 'Z';
        }

        return value;
    }

    /** A column name as a human label: created_by_id -> "Created By Id". */
    humanize_field(field) {
        return field.split('_').map(part => part.charAt(0).toUpperCase() + part.slice(1)).join(' ');
    }

    /** Client_Model -> "Client". */
    humanize_type(record_type) {
        return this.humanize_field(record_type.replace(/_Model$/, ''));
    }

    /** Whether a value is long enough to be worth a Show more toggle. */
    is_truncatable(text) {
        return text.length > Revision_History.TRUNCATE_AT;
    }

    /** Whether the reader expanded this value. */
    is_expanded(key) {
        return !!this.state.expanded[key];
    }

    /** The text to paint for one value - truncated unless the reader expanded it. */
    display_text(key, text) {
        if (!this.is_truncatable(text) || this.is_expanded(key)) {
            return text;
        }

        return text.slice(0, Revision_History.TRUNCATE_AT) + '...';
    }
}
