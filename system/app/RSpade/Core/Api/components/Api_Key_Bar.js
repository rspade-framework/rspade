/**
 * Api_Key_Bar - the shared tester-key control for the docs page.
 *
 * Holds ONE key in session-scoped Rsx_Storage (key 'apidocs_tester_key'); every Api_Tester on
 * the page reads it from there. A key can be pasted ("Use key") or minted for one hour
 * ("Mint temporary key") via Api_Docs_Controller.mint_tester_key - a minted key is revealed
 * once (copyable) and also shows in Settings > API Keys for revocation. Any change broadcasts
 * the DOM event 'apidocs:key_changed' so the testers refresh their key hint.
 */
class Api_Key_Bar extends Component {
    static STORAGE_KEY = 'apidocs_tester_key';

    on_create() {
        this.state = {
            key: Rsx_Storage.session_get(Api_Key_Bar.STORAGE_KEY),
            minted: null,
            expires: null,
        };
    }

    on_ready() {
        const that = this;

        const $use = this.$sid('use');
        if ($use.exists()) {
            $use.off('click.akb').on('click.akb', () => that._use());
        }

        const $clear = this.$sid('clear');
        if ($clear.exists()) {
            $clear.off('click.akb').on('click.akb', () => that._clear());
        }

        const $copy = this.$sid('copy_minted');
        if ($copy.exists()) {
            $copy.off('click.akb').on('click.akb', () => that._copy());
        }

        const $input = this.$sid('input');
        if ($input.exists()) {
            $input.off('keydown.akb').on('keydown.akb', (e) => {
                if (e.key === 'Enter') {
                    that._use();
                }
            });
        }

        this.$sid('mint').off('click.akb').click_async(async () => {
            await that._mint();
        });
    }

    _use() {
        const val = (this.$sid('input').val() || '').trim();
        if (!val) {
            return;
        }
        this._store(val);
        this.state.key = val;
        this.state.minted = null;
        this.render();
    }

    async _mint() {
        const res = await Api_Docs_Controller.mint_tester_key();
        this._store(res.key);
        this.state.key = res.key;
        this.state.minted = res.key;
        this.state.expires = res.expires_at ? Rsx_Time.relative(res.expires_at) : null;
        this.render();
    }

    _clear() {
        Rsx_Storage.session_remove(Api_Key_Bar.STORAGE_KEY);
        this.state.key = null;
        this.state.minted = null;
        this.state.expires = null;
        this.render();
        this._broadcast();
    }

    async _copy() {
        try {
            await navigator.clipboard.writeText(this.state.minted);
            const $span = this.$sid('copy_minted').find('span');
            const prev = $span.text();
            $span.text('Copied');
            setTimeout(() => $span.text(prev), 1200);
        } catch (e) {
            // Clipboard unavailable - non-critical.
        }
    }

    _store(k) {
        Rsx_Storage.session_set(Api_Key_Bar.STORAGE_KEY, k);
        this._broadcast();
    }

    _broadcast() {
        document.dispatchEvent(new CustomEvent('apidocs:key_changed'));
    }
}
