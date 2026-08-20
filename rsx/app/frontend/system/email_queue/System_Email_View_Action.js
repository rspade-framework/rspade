@route('/frontend/system/email_queue/view/:id')
@layout('Frontend_Spa_Layout')
@layout('System_Layout')
@spa('Frontend_Spa_Controller::index')
@title('Email Preview')
@auth('is_logged_in')
class System_Email_View_Action extends Spa_Action {
    scaffolded = true;

    on_create() {
        this.data.email = null;
        this.data.preview_html = '';
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const [email, preview] = await Promise.all([
                System_Email_Controller.queue_get({id: this.args.id}),
                System_Email_Controller.queue_preview({id: this.args.id}),
            ]);
            this.data.email = email;
            this.data.preview_html = preview.html;
        } catch (e) {
            this.data.error_data = e;
        }
        this.data.loading = false;
    }

    on_ready() {
        // Set iframe content after render
        const iframe = this.$sid('preview-frame');
        if (iframe.exists() && this.data.preview_html) {
            iframe.get(0).srcdoc = this.data.preview_html;
        }

        if (this.$sid('resend-btn').exists()) {
            this.$sid('resend-btn').click(async () => {
                if (!await Modal.confirm('Resend Email', 'Re-queue this email for delivery?')) return;
                await System_Email_Controller.queue_resend({id: this.args.id});
                this.reload();
            });
        }
    }

    async breadcrumb_label_active() { return 'Preview'; }
    async breadcrumb_parent() { return Rsx.Route('System_Email_Queue_Action'); }
}
