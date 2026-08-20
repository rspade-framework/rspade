/**
 * Section
 *
 * The workhorse section. Header anatomy lives in the template; this class only
 * stamps the $flush modifier. See section.jqhtml.
 */
class Section extends View_Section_Abstract {
    on_create() {
        if (this.args.flush) {
            this.$.addClass('Section--flush');
        }
    }
}
