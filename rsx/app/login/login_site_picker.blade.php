@rsx_id('Login_Site_Picker')
{{--
    The site picker: one button per site the signed-in identity can use, each selecting it
    through Site_Selection_Controller::select. $sites is Site_Selection_Controller::usable_sites().
    Rendered by the select-site page and the site-unauthorized page.
--}}
<div class="mb-4">
    @foreach ($sites as $site)
        <div class="d-grid mb-2">
            <a href="{{ Rsx::Route('Site_Selection_Controller::select', $site['site_id']) }}" class="btn btn-outline-primary">
                {{ $site['name'] }}
            </a>
        </div>
    @endforeach
</div>
