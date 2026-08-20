/**
 * api_code_samples(ep) - generate copyable client code samples for one endpoint.
 *
 * Pure function of the catalog endpoint spec (no per-endpoint authored samples). Path params
 * are substituted into the URL from their example (or a {name} placeholder); remaining params
 * become a GET query string or a POST JSON body. The Authorization header always uses a
 * literal {API_KEY} placeholder - the in-page tester injects the real key, but copyable code
 * should never embed a throwaway credential.
 *
 * Returns [{ lang, hl, code }] where hl is the highlight.js language class.
 */
function _api_sample_example_value(p) {
    if (p.example !== null && p.example !== undefined) {
        return p.example;
    }
    switch (p.type) {
        case 'int': return 0;
        case 'float': return 0.0;
        case 'bool': return false;
        default: return 'string';
    }
}

function _api_sample_shape(ep) {
    const verb = (ep.methods && ep.methods[0]) || 'GET';
    const params = ep.api_params || [];
    const path_params = [];
    const other_params = [];

    for (const p of params) {
        if (ep.pattern.indexOf(':' + p.name) !== -1) {
            path_params.push(p);
        } else {
            other_params.push(p);
        }
    }

    let path = ep.pattern;
    for (const p of path_params) {
        // A real example value is URL-encoded; the {name} placeholder stays readable.
        const substitute = (p.example !== null && p.example !== undefined)
            ? encodeURIComponent(String(p.example))
            : ('{' + p.name + '}');
        path = path.replace(':' + p.name, substitute);
    }

    return { verb, path, other_params };
}

function _api_sample_query(other_params) {
    if (!other_params.length) {
        return '';
    }
    const parts = other_params.map(p =>
        encodeURIComponent(p.name) + '=' + encodeURIComponent(String(_api_sample_example_value(p)))
    );
    return '?' + parts.join('&');
}

function _api_sample_body_object(other_params) {
    const obj = {};
    for (const p of other_params) {
        obj[p.name] = _api_sample_example_value(p);
    }
    return obj;
}

function api_code_samples(ep) {
    const origin = window.location.origin;
    const shape = _api_sample_shape(ep);
    const is_post = shape.verb === 'POST';
    const query = is_post ? '' : _api_sample_query(shape.other_params);
    const url = origin + shape.path + query;
    const body_obj = _api_sample_body_object(shape.other_params);
    const has_body = is_post && shape.other_params.length > 0;
    const body_json = JSON.stringify(body_obj, null, 2);
    const body_json_inline = JSON.stringify(body_obj);

    // curl
    let curl;
    if (is_post) {
        curl = 'curl -X POST "' + url + '" \\\n';
        curl += '  -H "Authorization: Bearer {API_KEY}"';
        if (has_body) {
            curl += ' \\\n  -H "Content-Type: application/json" \\\n';
            curl += "  -d '" + body_json_inline + "'";
        }
    } else {
        curl = 'curl "' + url + '" \\\n  -H "Authorization: Bearer {API_KEY}"';
    }

    // PHP (curl)
    let php = "<?php\n$ch = curl_init('" + url + "');\n";
    php += '$options = [\n    CURLOPT_RETURNTRANSFER => true,\n';
    if (is_post) {
        php += '    CURLOPT_POST => true,\n';
        if (has_body) {
            php += "    CURLOPT_POSTFIELDS => '" + body_json_inline + "',\n";
            php += "    CURLOPT_HTTPHEADER => [\n        'Authorization: Bearer {API_KEY}',\n        'Content-Type: application/json',\n    ],\n";
        } else {
            php += "    CURLOPT_HTTPHEADER => ['Authorization: Bearer {API_KEY}'],\n";
        }
    } else {
        php += "    CURLOPT_HTTPHEADER => ['Authorization: Bearer {API_KEY}'],\n";
    }
    php += '];\ncurl_setopt_array($ch, $options);\n$response = curl_exec($ch);\ncurl_close($ch);\n$data = json_decode($response, true);';

    // JavaScript (fetch)
    let js = 'const res = await fetch("' + url + '", {\n';
    if (is_post) {
        js += '  method: "POST",\n';
        if (has_body) {
            js += '  headers: {\n    "Authorization": "Bearer {API_KEY}",\n    "Content-Type": "application/json"\n  },\n';
            js += '  body: JSON.stringify(' + JSON.stringify(body_obj) + ')\n';
        } else {
            js += '  headers: { "Authorization": "Bearer {API_KEY}" }\n';
        }
    } else {
        js += '  headers: { "Authorization": "Bearer {API_KEY}" }\n';
    }
    js += '});\nconst data = await res.json();';

    // Python (requests)
    let py = 'import requests\n\n';
    if (is_post) {
        py += 'res = requests.post(\n    "' + origin + shape.path + '",\n';
        py += '    headers={"Authorization": "Bearer {API_KEY}"}';
        if (has_body) {
            py += ',\n    json=' + _api_python_dict(body_obj);
        }
        py += '\n)';
    } else {
        py += 'res = requests.get(\n    "' + origin + shape.path + '",\n';
        py += '    headers={"Authorization": "Bearer {API_KEY}"}';
        if (shape.other_params.length) {
            py += ',\n    params=' + _api_python_dict(body_obj_from(shape.other_params));
        }
        py += '\n)';
    }
    py += '\nprint(res.json())';

    // PowerShell (Invoke-RestMethod)
    let ps;
    if (is_post) {
        ps = '$headers = @{ "Authorization" = "Bearer {API_KEY}"';
        if (has_body) {
            ps += '; "Content-Type" = "application/json"';
        }
        ps += ' }\n';
        if (has_body) {
            ps += "$body = '" + body_json_inline + "'\n";
            ps += 'Invoke-RestMethod -Method Post -Uri "' + url + '" -Headers $headers -Body $body';
        } else {
            ps += 'Invoke-RestMethod -Method Post -Uri "' + url + '" -Headers $headers';
        }
    } else {
        ps = '$headers = @{ "Authorization" = "Bearer {API_KEY}" }\n';
        ps += 'Invoke-RestMethod -Method Get -Uri "' + url + '" -Headers $headers';
    }

    return [
        { lang: 'cURL', hl: 'bash', code: curl },
        { lang: 'PHP', hl: 'php', code: php },
        { lang: 'JavaScript', hl: 'javascript', code: js },
        { lang: 'Python', hl: 'python', code: py },
        { lang: 'PowerShell', hl: 'powershell', code: ps },
    ];
}

/**
 * api_tester_input(p) - HTML for one typed tester input, keyed by data-param="name".
 * bool -> select(true/false); int/float -> number input; string -> text input. Prefilled
 * from the param example when present. Attribute values are escaped via html().
 */
function api_tester_input(p) {
    const ex = (p.example !== null && p.example !== undefined) ? String(p.example) : '';
    const name_attr = ' data-param="' + html(p.name) + '"';

    if (p.type === 'bool') {
        const t_sel = ex === 'true' ? ' selected' : '';
        const f_sel = ex === 'false' ? ' selected' : '';
        return '<select class="Api_Tester__input"' + name_attr + '>'
            + '<option value="">(unset)</option>'
            + '<option value="true"' + t_sel + '>true</option>'
            + '<option value="false"' + f_sel + '>false</option>'
            + '</select>';
    }

    const type = (p.type === 'int' || p.type === 'float') ? 'number' : 'text';
    const step = (p.type === 'float') ? ' step="any"' : '';
    return '<input type="' + type + '"' + step + ' class="Api_Tester__input"' + name_attr
        + ' value="' + html(ex) + '" placeholder="' + html(ex || p.type) + '">';
}

// Build a params dict for GET query in Python style from an array of param specs.
function body_obj_from(other_params) {
    const obj = {};
    for (const p of other_params) {
        obj[p.name] = _api_sample_example_value(p);
    }
    return obj;
}

// Render a JS object as a Python dict literal (values: strings quoted, bools Title-cased).
function _api_python_dict(obj) {
    const entries = Object.keys(obj).map(k => {
        const v = obj[k];
        let rv;
        if (typeof v === 'boolean') {
            rv = v ? 'True' : 'False';
        } else if (typeof v === 'number') {
            rv = String(v);
        } else {
            rv = '"' + String(v).replace(/"/g, '\\"') + '"';
        }
        return '"' + k + '": ' + rv;
    });
    return '{' + entries.join(', ') + '}';
}
