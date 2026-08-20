@rsx_id('Backend_Index')
@rsx_extends('Backend_Layout')

@section('title', $title ?? 'Admin Dashboard')

@section('content')
<div class="py-4">
    <h1>{{ $title ?? 'Admin Dashboard' }}</h1>
    
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary mb-3">
                <div class="card-body">
                    <h5 class="card-title">Total Users</h5>
                    <p class="card-text display-6">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success mb-3">
                <div class="card-body">
                    <h5 class="card-title">Active Sessions</h5>
                    <p class="card-text display-6">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning mb-3">
                <div class="card-body">
                    <h5 class="card-title">Pending Tasks</h5>
                    <p class="card-text display-6">0</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info mb-3">
                <div class="card-body">
                    <h5 class="card-title">System Status</h5>
                    <p class="card-text display-6">OK</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Welcome, {{ $user->name ?? 'Administrator' }}</h5>
        </div>
        <div class="card-body">
            <p>This is the admin backend module. From here you can manage users, content, and system settings.</p>
            <p class="text-muted">Module location: <code>rsx/app/backend/</code></p>
        </div>
    </div>
</div>
@endsection