/**
 * View_Section_Abstract
 *
 * Abstract base for section/card chrome. See view_section_abstract.jqhtml.
 * Concrete children (Section, Card_Widget, Detail_Sidebar) inherit this JS class
 * either directly (class X extends View_Section_Abstract) or via the template
 * extends chain when they have no JS class of their own.
 */
class View_Section_Abstract extends Component {
    // Chrome is entirely SCSS on the .View_Section_Abstract root class.
    // No behavior lives here.
}
