@rsx_id('Backend_Layout')
<!DOCTYPE html>
<html lang="en">

  <head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>@yield('title', 'Admin Panel') - RSX Backend</title>

    {{-- Bundle includes --}}
    {!! Backend_Bundle::render() !!}
  </head>

  <body class="{{ rsx_body_class() }}">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
      <div class="container-fluid">
        <a class="navbar-brand" href="{{ Rsx::Route('Backend_Index_Controller::#index') }}">RSX Admin</a>
        <button class="navbar-toggler" data-bs-target="#navbarNav" data-bs-toggle="collapse" type="button">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="navbar-collapse collapse" id="navbarNav">
          <ul class="navbar-nav me-auto">
            <li class="nav-item">
              <a class="nav-link" href="{{ Rsx::Route('Backend_Index_Controller::#index') }}">Dashboard</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="{{ Rsx::Route('Backend_Users_Controller::#index') }}">Users</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="{{ Rsx::Route('Backend_Settings_Controller::#index') }}">Settings</a>
            </li>
          </ul>
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" href="{{ Rsx::Route('Login_Controller::logout') }}">Logout</a>
            </li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container-fluid">
      <div class="row">
        <nav class="col-md-2 bg-light sidebar py-3">
          <div class="position-sticky">
            <h6 class="sidebar-heading text-muted mb-1 mt-4 px-3">Management</h6>
            <ul class="nav flex-column">
              <li class="nav-item">
                <a class="nav-link" href="{{ Rsx::Route('Backend_Index_Controller::#index') }}">Overview</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="{{ Rsx::Route('Backend_Users_Controller::#index') }}">Users</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="{{ Rsx::Route('Backend_Content_Controller::#index') }}">Content</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="{{ Rsx::Route('Backend_Reports_Controller::#index') }}">Reports</a>
              </li>
            </ul>
          </div>
        </nav>

        <main class="col-md-10 ms-sm-auto px-md-4">
          @yield('content')
        </main>
      </div>
    </div>
  </body>

</html>
