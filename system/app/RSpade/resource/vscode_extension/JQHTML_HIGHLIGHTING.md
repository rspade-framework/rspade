# JQHTML Component Highlighting in RSpade VS Code Extension

## Version 0.1.39 - Added JQHTML Component Support

The RSpade VS Code Extension now includes syntax highlighting for JQHTML components in Blade PHP files.

## What Gets Highlighted

### 1. Uppercase Component Tags
Any tag starting with an uppercase letter is treated as a JQHTML component and highlighted distinctively:

```blade
{{-- These get highlighted as JQHTML components --}}
<User_Card name="John" email="john@example.com" />
<Counter_Widget title="My Counter" initial_value="0" />
<DataGrid_Component rows="data" />
<MyCustomWidget>Content here</MyCustomWidget>

{{-- Regular HTML tags remain standard --}}
<div>
<span>
<button>
```

### 2. JQHTML Slots
Slot tags for components with multiple content areas:

```blade
{{-- Slot tags are highlighted specially --}}
<DataGrid>
    <Slot:header>Name | Email | Status</Slot:header>
    <Slot:row><%= row.name %> | <%= row.email %></Slot:row>
    <Slot:empty>No data found</Slot:empty>
</DataGrid>
```

### 3. Blade Directives for JQHTML
The `@jqhtml` directive and `Jqhtml::component()` calls:

```blade
{{-- Blade directive --}}
@jqhtml('User_Card', ['name' => 'John'])

{{-- PHP helper --}}
{!! Jqhtml::component('User_Card', ['name' => 'John']) !!}
```

## Color Scheme

The extension uses the TextMate scope `entity.name.class.component.jqhtml` which provides:

| Theme | Typical Component Color |
|-------|-------------------------|
| Dark+ (VS Code default) | Cyan (#4EC9B0) |
| Light+ (VS Code default) | Teal (#267F99) |
| Monokai | Green (#A6E22E) |
| Dracula | Cyan (#8BE9FD) |
| Solarized Dark | Cyan (#2AA198) |
| Nord | Teal (#88C0D0) |

## How It Works

The extension injects TextMate grammar rules into PHP and Blade files to:

1. **Identify Component Tags**: Patterns match tags starting with uppercase letters
2. **Highlight Component Names**: Apply distinctive coloring to component names
3. **Preserve Blade Syntax**: Handle Blade expressions within component attributes
4. **Support Slots**: Recognize and highlight `<Slot:slotname>` syntax

## Supported Patterns

### Basic Components
```blade
<User_Card name="Alice" />
<Counter_Widget initial_value="5" />
```

### Components with Content
```blade
<Container theme="dark">
    <h2>Dashboard</h2>
    <p>Content here</p>
</Container>
```

### Components with Blade Expressions
```blade
<User_Card
    name="{{ $user->name }}"
    email="{{ $user->email }}"
    :data="@json($userData)"
/>
```

### Components with Conditional Attributes
```blade
<DataTable
    @if($sortable)
        sortable="true"
    @endif
    :items="@json($items)"
/>
```

### Nested Components
```blade
<Dashboard>
    @foreach($users as $user)
        <User_Card :user="$user" />
    @endforeach
</Dashboard>
```

## Installation

The JQHTML highlighting is included in RSpade VS Code Extension version 0.1.39 and above.

To install or update:
```bash
# From the extension directory
./build.sh

# Then in VS Code
# Press Ctrl+Shift+X → ... → Install from VSIX
# Select: rspade-framework.vsix
```

## Technical Details

### Grammar File Location
`/app/RSpade/resource/vscode_extension/syntaxes/blade-jqhtml.tmLanguage.json`

### Injection Points
The grammar is injected into:
- `text.html.php.blade` - Laravel Blade files
- `text.html.php` - Standard PHP files with HTML

### Pattern Matching
- Component opening tags: `(<)([A-Z][\\w_]*)(?=\\s|>)`
- Component closing tags: `(</)([A-Z][\\w_]*)(>)`
- Slot tags: `(<Slot:)(\\w+)(>)` and `(</Slot:)(\\w+)(>)`

## Troubleshooting

If highlighting doesn't appear:
1. Ensure you have version 0.1.39+ of the RSpade extension
2. Reload VS Code window (Ctrl+R / Cmd+R)
3. Check that file is recognized as PHP/Blade
4. Verify your color theme supports the scope

## Future Enhancements

Potential improvements for future versions:
- IntelliSense for component names
- Auto-completion for component attributes
- Go-to-definition for component classes
- Hover documentation for components
- Component validation and error checking