@rsx_id('Dev_Layout')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title', 'Dev') - {{ config('rspade.name', 'RSX') }}</title>

    {{-- Bundle includes (CDN assets like Bootstrap Icons are included via bundle) --}}
    {!! Dev_Bundle::render() !!}
</head>

<body class="{{ rsx_body_class() }}">
    {{-- Sidebar Navigation --}}
    <nav class="app-sidebar">
      {{-- Brand - Desktop only --}}
      <div class="sidebar-brand d-none d-desktop-block">
        <a href="{{ Rsx::Route('Dev_Index_Controller') }}">
          Dev Tools
        </a>
      </div>

      {{-- Mobile Navbar (full-width on mobile) --}}
      <div class="d-desktop-none">
        <nav class="navbar navbar-expand bg-body border-bottom">
          <div class="container-fluid">
            <a class="navbar-brand" href="{{ Rsx::Route('Dev_Index_Controller') }}">
              Dev Tools
            </a>
            <button class="navbar-toggler" data-bs-target="#sidebarNav" data-bs-toggle="collapse" type="button">
              <span class="navbar-toggler-icon"></span>
            </button>
          </div>
        </nav>
      </div>

      {{-- Main Navigation Links --}}
      @php
          $nav_sections = [
              [
                  'title' => 'Dev Tools',
                  'items' => [
                      [
                          'label' => 'Home',
                          'icon' => 'bi-house-door',
                          'href' => Rsx::Route('Dev_Index_Controller'),
                      ],
                      [
                          'label' => 'SPA Test',
                          'icon' => 'bi-router',
                          'href' => Rsx::Route('Dev_Spa_Action'),
                      ],
                      [
                          'label' => 'Modals',
                          'icon' => 'bi-window',
                          'href' => Rsx::Route('Dev_Modals_Controller'),
                      ],
                      [
                          'label' => 'Attachments',
                          'icon' => 'bi-paperclip',
                          'href' => Rsx::Route('Dev_Attachments_Controller'),
                      ],
                      [
                          'label' => 'Flash Alerts',
                          'icon' => 'bi-bell',
                          'href' => Rsx::Route('Dev_Flash_Controller'),
                      ],
                      [
                          'label' => 'ACL Tester',
                          'icon' => 'bi-shield-lock',
                          'href' => Rsx::Route('Dev_Acl_Controller'),
                      ],
                  ],
              ],
          ];
      @endphp
      <Sidebar_Nav class="Dev_Layout__sidebar-nav" $sections="{!! json_encode($nav_sections) !!}" />
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
                @if(\App\RSpade\Core\Session\Session::is_logged_in())
                  {{ explode('@', \App\RSpade\Core\Session\Session::get_user()->email)[0] }}
                @else
                  Guest
                @endif
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow">
                @if(\App\RSpade\Core\Session\Session::is_logged_in())
                  <li><a class="dropdown-item" href="#">
                    <i class="bi bi-person"></i> Profile
                  </a></li>
                  <li><a class="dropdown-item" href="#">
                    <i class="bi bi-gear"></i> Settings
                  </a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="{{ Rsx::Route('Login_Index_Controller::#logout') }}">
                    <i class="bi bi-box-arrow-right"></i> Sign Out
                  </a></li>
                @else
                  <li><a class="dropdown-item" href="{{ Rsx::Route('Login_Index_Controller::#show_login') }}">
                    Login
                  </a></li>
                @endif
              </ul>
            </div>
          </div>
        </div>
      </nav>

      {{-- Page Content --}}
      <main class="page-content @hasSection('full_width')@else page-content--constrained @endif">
        @yield('content')
      </main>
    </div>
</body>
</html>