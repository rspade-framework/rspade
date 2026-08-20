/**
 * Party list page - the Class-Table Inheritance reference (see man detail_tables).
 */
@route('/party')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Parties')
@auth('is_logged_in')
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
        return `
            <div class="dropdown d-inline-block">
                <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-plus-circle"></i> New Party
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="${Rsx.Route('Party_Edit_Action', {type: Party_Model.TYPE_PERSON})}">Person</a></li>
                    <li><a class="dropdown-item" href="${Rsx.Route('Party_Edit_Action', {type: Party_Model.TYPE_COMPANY})}">Company</a></li>
                    <li><a class="dropdown-item" href="${Rsx.Route('Party_Edit_Action', {type: Party_Model.TYPE_GROUP})}">Group</a></li>
                </ul>
            </div>
        `;
    }
}
