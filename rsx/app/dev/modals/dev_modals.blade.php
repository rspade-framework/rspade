@rsx_id('Dev_Modals')
@rsx_extends('Dev_Layout')

@section('title', 'Modal Testing')

@section('content')
<Page>
    <Page_Header>
        <Page_Header_Left>
            <Page_Title>Modal System Testing</Page_Title>
        </Page_Header_Left>
    </Page_Header>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Simple Dialogs</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Basic alert, confirm, and prompt dialogs</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" id="test-alert">Alert</button>
                        <button type="button" class="btn btn-primary" id="test-alert-title">Alert with Title</button>
                        <button type="button" class="btn btn-info" id="test-confirm">Confirm</button>
                        <button type="button" class="btn btn-info" id="test-confirm-title">Confirm with Title</button>
                        <button type="button" class="btn btn-warning" id="test-prompt">Prompt</button>
                        <button type="button" class="btn btn-warning" id="test-prompt-default">Prompt with Default</button>
                        <button type="button" class="btn btn-warning" id="test-prompt-multiline">Prompt Multiline</button>
                        <button type="button" class="btn btn-warning" id="test-prompt-rich">Prompt Rich Text</button>
                        <button type="button" class="btn btn-danger" id="test-prompt-validation">Prompt with Validation</button>
                        <button type="button" class="btn btn-success" id="test-select">Select</button>
                        <button type="button" class="btn btn-success" id="test-select-default">Select with Default</button>
                    </div>
                    <div id="simple-result" class="alert alert-info mt-3" style="display: none;">
                        Result: <span id="simple-result-text"></span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Custom Modals</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Custom content and button configurations</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success" id="test-custom-2btn">Two Buttons</button>
                        <button type="button" class="btn btn-success" id="test-custom-3btn">Three Buttons</button>
                        <button type="button" class="btn btn-success" id="test-custom-danger">Dangerous Action</button>
                        <button type="button" class="btn btn-success" id="test-custom-jquery">jQuery Content</button>
                        <button type="button" class="btn btn-success" id="test-custom-wide">Wide Modal (1200px)</button>
                    </div>
                    <div id="custom-result" class="alert alert-info mt-3" style="display: none;">
                        Result: <span id="custom-result-text"></span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Special Behaviors</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Unclosable, queuing, and error handling</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary" id="test-unclosable">Unclosable Modal</button>
                        <button type="button" class="btn btn-secondary" id="test-queue">Queue 3 Modals</button>
                        <button type="button" class="btn btn-danger" id="test-error">Error Modal</button>
                        <button type="button" class="btn btn-secondary" id="test-tall">Tall Content (Scroll)</button>
                    </div>
                    <div id="special-result" class="alert alert-info mt-3" style="display: none;">
                        Result: <span id="special-result-text"></span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Form Modals</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Form components with validation and submission handling</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success" id="test-form-simple">Simple Form</button>
                        <button type="button" class="btn btn-success" id="test-form-validation">Form with Validation</button>
                        <button type="button" class="btn btn-success" id="test-form-prefilled">Pre-filled Form</button>
                        <button type="button" class="btn btn-success" id="test-form-pin">PIN Verification</button>
                    </div>
                    <div id="form-result" class="alert alert-info mt-3" style="display: none;">
                        Result: <span id="form-result-text"></span>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Modal State</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Current modal system state</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary" id="check-state">Check State</button>
                        <button type="button" class="btn btn-danger" id="force-close">Force Close</button>
                    </div>
                    <div id="state-result" class="alert alert-secondary mt-3" style="display: none;">
                        <pre id="state-result-text" class="mb-0"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</Page>
@endsection