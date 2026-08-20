@rsx_id('Root_Layout')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">
    <title>@yield('title', 'Root Admin') - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle includes (CDN assets like Bootstrap Icons are included via bundle) --}}
    {!! Root_Bundle::render() !!}
</head>

<body class="{{ rsx_body_class() }}">
    {{-- Sidebar Navigation --}}
    <nav class="app-sidebar">
        {{-- Brand - Desktop only --}}
        <div class="sidebar-brand d-none d-desktop-block">
            <a href="{{ Rsx::Route('Root_Dashboard_Controller') }}">
                Root Admin
            </a>
        </div>

        {{-- Mobile Navbar (full-width on mobile) --}}
        <div class="d-desktop-none">
            <nav class="navbar navbar-expand bg-body border-bottom">
                <div class="container-fluid">
                    <a class="navbar-brand" href="{{ Rsx::Route('Root_Dashboard_Controller') }}">
                        Root Admin
                    </a>
                    <button class="navbar-toggler" data-bs-target="#sidebarNav" data-bs-toggle="collapse"
                        type="button">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>
        </div>

        {{-- Main Navigation Links --}}
        @php
            $nav_sections = [
                [
                    'title' => 'Root Administration',
                    'items' => [
                        [
                            'label' => 'Dashboard',
                            'icon' => 'bi-house-door',
                            'href' => Rsx::Route('Root_Dashboard_Controller'),
                        ],
                        [
                            'label' => 'Sites',
                            'icon' => 'bi-building',
                            'href' => Rsx::Route('Root_Sites_Controller'),
                        ],
                        [
                            'label' => 'Email',
                            'icon' => 'bi-envelope',
                            'href' => Rsx::Route('Root_Email_Controller'),
                            'subitems' => [
                                [
                                    'label' => 'Status',
                                    'href' => Rsx::Route('Root_Email_Controller'),
                                ],
                                [
                                    'label' => 'History',
                                    'href' => Rsx::Route('Root_Email_Controller') . '/history',
                                ],
                                [
                                    'label' => 'Templates',
                                    'href' => Rsx::Route('Root_Email_Controller') . '/templates',
                                ],
                            ],
                        ],
                        [
                            'label' => 'System',
                            'icon' => 'bi-gear',
                            'href' => Rsx::Route('Root_System_Controller'),
                        ],
                    ],
                ],
            ];
        @endphp
        <Sidebar_Nav class="Root_Layout__sidebar-nav" $sections="{!! json_encode($nav_sections) !!}" />
    </nav>

    {{-- Main Content Area --}}
    <div class="app-content">
        {{-- Top Header Navbar --}}
        <nav class="app-header navbar navbar-expand bg-dark navbar-dark">
            <div class="container-fluid">
                {{-- Right side content --}}
                <div class="d-flex align-items-center ms-auto">
                    {{-- User Dropdown --}}
                    <div class="dropdown">
                        <button class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" type="button">
                            @if (Session::is_logged_in())
                                {{ explode('@', Session::get_user()->email)[0] }}
                            @else
                                Guest
                            @endif
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            @if (Session::is_logged_in())
                                <li>
                                    <a class="dropdown-item" href="#">
                                        <i class="bi bi-person"></i> Profile
                                    </a>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="{{ Rsx::Route('Login_Controller::logout') }}">
                                        <i class="bi bi-box-arrow-right"></i> Sign Out
                                    </a>
                                </li>
                            @else
                                <li>
                                    <a class="dropdown-item"
                                        href="{{ Rsx::Route('Login_Controller::#show_login') }}">Login</a>
                                </li>
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        {{-- Page Content --}}
        <main class="page-content @hasSection('full_width')
@elseif(View::hasSection('constrained_wider'))
page-content--constrained-wider
@else
page-content--constrained
@endif">
            @yield('content')
        </main>
    </div>
</body>

</html>
