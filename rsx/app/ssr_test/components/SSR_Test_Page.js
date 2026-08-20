class SSR_Test_Page extends Component {

    on_create() {
        this.data.stats = [];
        this.data.plans = [];
        this.data.team = [];
    }

    async on_load() {
        const result = await SSR_Test_Controller.get_page_data();
        this.data.stats = result.stats;
        this.data.plans = result.plans;
        this.data.team = result.team;
    }

}
