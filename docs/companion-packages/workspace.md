# Prism Workspace

Read and write files inside an owner-scoped Laravel storage directory. Workspace guards paths and local filesystem boundaries. It provides file operations; it does not execute code or expose model tools automatically.

## Installation

```bash
composer require particle-academy/prism-workspace
php artisan vendor:publish --tag=prism-workspace-config
```

Settings live in `config/prism-workspace.php`. Configure the underlying Laravel storage disk and authorize which owners your application can address.

## Basic usage

```php
use Prism\Workspace\Facades\PrismWorkspace;

// $ownerKey is a stable identifier chosen after application authorization.
$workspace = PrismWorkspace::for($ownerKey);

$workspace->write('reports/q1.md', '# Q1 report');
$content = $workspace->read('reports/q1.md');

foreach ($workspace->list('reports', recursive: true) as $entry) {
    echo $entry->path;
}
```

`for()` accepts a Harness session, a `WorkspaceOwner`, an Eloquent model or a stable string. It resolves the same directory for the same address. A custom owner implements `Prism\Workspace\Contracts\WorkspaceOwner::workspaceKey(): string`; keep its key stable across workers and requests.

## File operations

| Methods | Result / behavior |
|---|---|
| `address()`, `path($path)` | Workspace address; guarded normalized path |
| `exists($path)`, `read($path)`, `readStream($path)` | Existence, bytes or a stream resource |
| `write($path, $contents)`, `writeStream($path, $resource)`, `append($path, $contents)` | Write operations; return the workspace |
| `copy($from, $to)`, `move($from, $to)` | Guard both paths |
| `delete($path)`, `makeDirectory($path)`, `deleteDirectory($path)` | Mutations; directory deletion is recursive |
| `size($path)`, `lastModified($path)` | Byte count or modification timestamp |
| `list($directory = '', $recursive = true)` | LazyCollection of WorkspaceEntry objects |
| `clear()` | Delete the workspace contents, retaining its directory |

Entries expose `path`, `isDirectory`, nullable `size` and nullable `lastModified`. Close stream resources when finished. Authorize deletion and `clear()` as destructive application actions.

`disk()` returns the underlying adapter and bypasses the Workspace guards. `root()` exposes the adapter path; use it only where your storage driver supports local paths. Keep these escape hatches out of model-facing tool APIs.

## Configuration and authorization

| Key | Default | Environment variable |
|---|---|---|
| `disk` | `local` | `PRISM_WORKSPACE_DISK` |
| `root` | `workspaces` | `PRISM_WORKSPACE_ROOT` |
| `max_path_length` | 1024 | Set in PHP config |
| `max_segment_length` | 255 | Set in PHP config |
| `windows_max_path` | 259 | Set in PHP config; null disables this local Windows bound |
| `authorize` | false | `PRISM_WORKSPACE_AUTHORIZE` |
| `gate_prefix` | `workspace` | `PRISM_WORKSPACE_GATE_PREFIX` |

Authorization is off by default. Enable it to call Laravel Gate abilities `workspace.read`, `workspace.write`, `workspace.delete` and `workspace.list`. Policies receive the authenticated user, Workspace and guarded path; root listing and `clear()` can supply no path. Configure authentication for queue work as needed; a storage scope alone does not authorize a user to access that scope.

Local disks also check the filesystem destination for links escaping the root. This is not operating-system isolation: code with direct filesystem access or access to `disk()` can bypass the wrapper.

## Refusals and storage failures

Catch `Prism\Workspace\Exceptions\PathRefused` and branch on `$error->refusal->value`. The `Refusal` codes are:

- `path_traverses_outside_workspace`, `path_is_absolute`, `path_is_unc_or_device_namespace`
- `path_contains_null_byte`, `path_contains_control_character`, `path_contains_invisible_character`
- `path_contains_separator_homoglyph`, `path_is_not_valid_utf8`, `path_contains_encoded_separator`
- `path_contains_alternate_data_stream`, `path_is_reserved_device_name`, `path_has_edge_dot_or_space`
- `path_uses_short_name_alias`, `path_uses_home_expansion`, `path_is_empty`
- `path_too_long`, `path_segment_too_long`, `path_escapes_workspace_via_link`

`WorkspaceFailed` exposes `$error->fault` with `workspace_file_missing`, `workspace_write_failed`, `workspace_delete_failed` or `workspace_owner_not_addressable`. Laravel authorization failures are separate from path and disk failures. Raw `$error->path` can contain hostile characters; do not print it unescaped.

## Checking your storage configuration

Run the shipped escape corpus against an isolated workspace on the same disk configuration:

```php
use Prism\Workspace\Facades\PrismWorkspace;
use Prism\Workspace\Security\CorpusRunner;

// $testOwnerKey identifies a dedicated workspace your application owns.
$report = (new CorpusRunner)->against(PrismWorkspace::for($testOwnerKey));

if (! $report->passed()) {
    throw new RuntimeException($report->summary());
}
```

The runner attempts refused paths and checks for escaped marker files. A failed boundary can write those markers, so use an isolated test owner and surrounding directory. The result checks that configuration; it does not establish isolation for arbitrary code running in your application.

See [Harness](/companion-packages/harness) for sessions and the [Workspace package guide](https://github.com/Particle-Academy/prism-workspace) for custom storage integration.
