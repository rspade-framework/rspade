@rsx_id('Dev_Acl')
@rsx_extends('Dev_Layout')

@section('title', 'ACL Tester')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">ACL Tester</h1>
            <p class="text-muted">Test and verify role-based access control system</p>
        </div>
    </div>

    {{-- Test Users Controls --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Test Users</h5>
                    <div>
                        <button type="button" id="btn-populate" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle"></i> Populate Test Users
                        </button>
                        <button type="button" id="btn-delete" class="btn btn-danger btn-sm">
                            <i class="bi bi-trash"></i> Delete Test Users
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="mb-0">Test users have email addresses in the <code>@rspade.test</code> domain. Click "Populate Test Users" to create one user for each role.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Role Definitions --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Role Definitions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Constant</th>
                                    <th>Label</th>
                                    <th>Permissions</th>
                                    <th>Can Admin Roles</th>
                                    <th>Selectable</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($roles as $role)
                                <tr>
                                    <td><code>{{ $role['id'] }}</code></td>
                                    <td><code>{{ $role['constant'] }}</code></td>
                                    <td>{{ $role['label'] }}</td>
                                    <td>
                                        @foreach($role['permissions'] as $perm_id)
                                            <span class="badge bg-success">{{ $permissions[$perm_id] ?? $perm_id }}</span>
                                        @endforeach
                                        @if(empty($role['permissions']))
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach($role['can_admin_roles'] as $admin_role_id)
                                            <span class="badge bg-info">{{ $roles[$admin_role_id]['label'] ?? $admin_role_id }}</span>
                                        @endforeach
                                        @if(empty($role['can_admin_roles']))
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($role['selectable'])
                                            <span class="badge bg-success">Yes</span>
                                        @else
                                            <span class="badge bg-secondary">No</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Users and Permissions --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Users and Permissions</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Email</th>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>ROOT</th>
                                    <th>BILLING</th>
                                    <th>SETTINGS</th>
                                    <th>USERS</th>
                                    <th>ACTIVITY</th>
                                    <th>EDIT</th>
                                    <th>VIEW</th>
                                    <th>API</th>
                                    <th>EXPORT</th>
                                    <th>Supplementary</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($users as $user)
                                <tr class="{{ $user['is_test_user'] ? 'table-warning' : '' }}">
                                    <td>{{ $user['id'] }}</td>
                                    <td>
                                        {{ $user['email'] }}
                                        @if($user['is_test_user'])
                                            <span class="badge bg-warning text-dark">test</span>
                                        @endif
                                    </td>
                                    <td>{{ $user['name'] }}</td>
                                    <td>
                                        <span class="badge bg-primary">{{ $user['role_label'] }}</span>
                                        <small class="text-muted">({{ $user['role_id'] }})</small>
                                    </td>
                                    @foreach(['MANAGE_SITES_ROOT', 'MANAGE_SITE_BILLING', 'MANAGE_SITE_SETTINGS', 'MANAGE_SITE_USERS', 'VIEW_USER_ACTIVITY', 'EDIT_DATA', 'VIEW_DATA', 'API_ACCESS', 'DATA_EXPORT'] as $perm)
                                        <td class="text-center">
                                            @if($user['permissions'][$perm])
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            @else
                                                <i class="bi bi-x-circle text-danger"></i>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        @if(!empty($user['supplementary']['grants']))
                                            <span class="badge bg-success">+{{ count($user['supplementary']['grants']) }}</span>
                                        @endif
                                        @if(!empty($user['supplementary']['denies']))
                                            <span class="badge bg-danger">-{{ count($user['supplementary']['denies']) }}</span>
                                        @endif
                                        @if(empty($user['supplementary']['grants']) && empty($user['supplementary']['denies']))
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Permission Legend --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Permission Reference</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Core Permissions</h6>
                            <ul class="list-unstyled mb-0">
                                <li><code>PERM_MANAGE_SITES_ROOT (1)</code> - Root admin only</li>
                                <li><code>PERM_MANAGE_SITE_BILLING (2)</code> - Site owner and above</li>
                                <li><code>PERM_MANAGE_SITE_SETTINGS (3)</code> - Site admin and above</li>
                                <li><code>PERM_MANAGE_SITE_USERS (4)</code> - Site admin and above</li>
                                <li><code>PERM_VIEW_USER_ACTIVITY (5)</code> - Manager and above</li>
                                <li><code>PERM_EDIT_DATA (6)</code> - User and above</li>
                                <li><code>PERM_VIEW_DATA (7)</code> - Viewer and above</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Supplementary-Only Permissions</h6>
                            <ul class="list-unstyled mb-0">
                                <li><code>PERM_API_ACCESS (8)</code> - Must be explicitly granted</li>
                                <li><code>PERM_DATA_EXPORT (9)</code> - Must be explicitly granted</li>
                            </ul>
                            <h6 class="mt-3">Resolution Order</h6>
                            <ol class="mb-0">
                                <li>DISABLED role = deny all</li>
                                <li>Supplementary DENY = deny</li>
                                <li>Supplementary GRANT = grant</li>
                                <li>Role default = grant if in list</li>
                                <li>Deny</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
