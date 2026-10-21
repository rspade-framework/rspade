/**
 * Party list page - the Class-Table Inheritance reference (see man detail_tables).
 */
@route('/party')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Parties')
@auth('is_logged_in', 'can_view_data')
class Party_Index_Action extends Spa_Action {
    // Composes with Page_Scaffold: the layout yields max-width and page
    // padding to the scaffold (see Frontend_Spa_Layout.on_action).
    scaffolded = true;

    async on_load() {
        // DataGrid loads its own data via Ajax.
    }


    async breadcrumb_label() {
        return 'Parties';
    }

    async breadcrumb_label_active() {
        return 'Class-Table Inheritance demo';
    }

    page_actions() {
        return { component: 'Party_Index_Page_Actions' };
    }
}
