@rsx_id('Dev_Flash')
@rsx_extends('Dev_Layout')

@section('title', 'Flash Alert Testing')

@section('content')
<Page>
    <Page_Header>
        <Page_Header_Left>
            <Page_Title>Flash Alert System Testing</Page_Title>
        </Page_Header_Left>
    </Page_Header>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Server-Side Flash Alerts (Post-Redirect)</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Tests Flash::success() → redirect → client receives via window.rsxapp.flash_alerts
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ Rsx::Route('Dev_Flash_Controller::test_server_success') }}" class="btn btn-success">
                            Success Flash
                        </a>
                        <span class="text-muted align-self-center">Creates Flash::success() then redirects back</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a href="{{ Rsx::Route('Dev_Flash_Controller::test_server_error') }}" class="btn btn-danger">
                            Error Flash
                        </a>
                        <span class="text-muted align-self-center">Creates Flash::error() then redirects back</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a href="{{ Rsx::Route('Dev_Flash_Controller::test_server_info') }}" class="btn btn-info">
                            Info Flash
                        </a>
                        <span class="text-muted align-self-center">Creates Flash::info() then redirects back</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a href="{{ Rsx::Route('Dev_Flash_Controller::test_server_warning') }}" class="btn btn-warning">
                            Warning Flash
                        </a>
                        <span class="text-muted align-self-center">Creates Flash::warning() then redirects back</span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Client-Side Flash Alerts (Direct JavaScript)</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Tests Flash_Alert.success() directly - bypasses server, pure client-side display
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success" id="test-client-success">
                            Success Flash
                        </button>
                        <span class="text-muted align-self-center">Calls Flash_Alert.success() directly</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-danger" id="test-client-error">
                            Error Flash
                        </button>
                        <span class="text-muted align-self-center">Calls Flash_Alert.error() directly</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-info" id="test-client-info">
                            Info Flash
                        </button>
                        <span class="text-muted align-self-center">Calls Flash_Alert.info() directly</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-warning" id="test-client-warning">
                            Warning Flash
                        </button>
                        <span class="text-muted align-self-center">Calls Flash_Alert.warning() directly</span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Ajax Flash Alerts (No Page Reload)</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Tests Flash::success() in Ajax endpoint → client receives via response.flash_alerts
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success" id="test-ajax-success">
                            Success Flash
                        </button>
                        <span class="text-muted align-self-center">Ajax call that sets Flash::success() on server</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-danger" id="test-ajax-error">
                            Error Flash
                        </button>
                        <span class="text-muted align-self-center">Ajax call that sets Flash::error() on server</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-info" id="test-ajax-info">
                            Info Flash
                        </button>
                        <span class="text-muted align-self-center">Ajax call that sets Flash::info() on server</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-warning" id="test-ajax-warning">
                            Warning Flash
                        </button>
                        <span class="text-muted align-self-center">Ajax call that sets Flash::warning() on server</span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Queue Testing</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Tests multiple flash messages (2.5s spacing between alerts)
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" id="test-queue-client">
                            Queue 4 Client Alerts
                        </button>
                        <span class="text-muted align-self-center">Fires 4 flash alerts in rapid succession</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-primary" id="test-queue-ajax">
                            Queue 4 Ajax Alerts
                        </button>
                        <span class="text-muted align-self-center">Ajax call that creates 4 server-side flash messages</span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Persistence Testing (sessionStorage)</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Tests sessionStorage persistence: Ajax response with flash message + immediate redirect.
                        The flash message should persist across the navigation and display on the new page.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success" id="test-ajax-redirect">
                            Ajax + Redirect Test
                        </button>
                        <span class="text-muted align-self-center">
                            Ajax call returns flash message + redirect URL. If flash displays after redirect, persistence works!
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</Page>
@endsection
