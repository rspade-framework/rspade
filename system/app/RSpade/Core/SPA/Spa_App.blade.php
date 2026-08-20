@rsx_id('Spa_App')
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="ie=edge" http-equiv="X-UA-Compatible">

    {{-- Bundle includes - dynamically rendered based on SPA controller --}}
    {!! $bundle::render() !!}
</head>

<body class="{{ rsx_body_class() }}">
    <div id="spa-root"></div>
</body>

</html>
