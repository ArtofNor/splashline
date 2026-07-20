<?php
declare(strict_types=1);

use Scriptwriter\ComicParser;
use Scriptwriter\ComicRenderer;
use Scriptwriter\FountainParser;
use Scriptwriter\Renderer;
use Scriptwriter\Support;

require __DIR__ . '/../src/FountainParser.php';
require __DIR__ . '/../src/Renderer.php';
require __DIR__ . '/../src/ComicParser.php';
require __DIR__ . '/../src/ComicRenderer.php';
require __DIR__ . '/../src/Support.php';

const SCRIPTS_DIR = __DIR__ . '/../scripts';

// Detection and filename logic lives in Scriptwriter\Support (shared with the
// test suite); these are thin local aliases.
function is_comic(string $name): bool
{
    return Support::isComicName($name);
}

function sniff_comic(string $content): ?bool
{
    return Support::sniffComic($content);
}

function is_comic_file(string $name, string $content): bool
{
    return Support::isComicFile($name, $content);
}

function script_path(string $name): ?string
{
    return Support::scriptPath(SCRIPTS_DIR, $name);
}

function safe_filename(string $input, bool $comicDefault = false): ?string
{
    return Support::safeFilename($input, $comicDefault);
}

/** @return list<string> */
function list_scripts(): array
{
    $files = array_merge(
        glob(SCRIPTS_DIR . '/*.fountain') ?: [],
        glob(SCRIPTS_DIR . '/*.md') ?: [],
    );
    $names = array_map('basename', $files);
    natcasesort($names);

    return array_values($names);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

$action = $_GET['action'] ?? 'list';
$file = $_GET['f'] ?? '';

// Which format a script is written in. Declared at the New button, then
// carried by the editor so the live surface and the saved extension agree
// before there is any content to sniff. Empty for an existing file, which
// knows what it is from its own bytes and name.
$kind = ($_GET['kind'] ?? '') === 'comic' ? 'comic'
    : (($_GET['kind'] ?? '') === 'screenplay' ? 'screenplay' : '');

// --- Save (POST) -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $ajax = isset($_POST['ajax']);

    // Small helper: reply as JSON (for the live editor) or plain/redirect.
    $fail = static function (int $code, string $msg) use ($ajax): never {
        http_response_code($code);
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $msg]);
        } else {
            echo $msg;
        }
        exit;
    };

    $content = (string) ($_POST['content'] ?? '');
    // Content first, as always; the editor's declared kind only breaks the tie
    // for a script too young to sniff — a comic that is still just a title
    // block would otherwise be saved as .fountain.
    $declared = ($_POST['kind'] ?? '') === 'comic';
    $name = safe_filename((string) ($_POST['filename'] ?? ''), sniff_comic($content) ?? $declared);
    if ($name === null) {
        $fail(400, 'Please give it a title with at least one letter or number.');
    }

    // Never clobber a *different* existing script. `original` is the file being
    // edited (empty when creating new); a save may only overwrite that same
    // file. file_exists is case-insensitive on macOS, so this also catches
    // "Night's End" colliding with an existing "nights-end".
    $original = basename((string) ($_POST['original'] ?? ''));
    if (file_exists(SCRIPTS_DIR . '/' . $name) && strcasecmp($name, $original) !== 0) {
        $fail(409, 'A script named "' . $name . '" already exists. Open it to edit, or pick a different title.');
    }

    file_put_contents(SCRIPTS_DIR . '/' . $name, $content);

    // A save under a new name while editing an existing file is a rename:
    // remove the old file once the new one is safely written. (strcasecmp
    // guard: on case-insensitive macOS, a case-only rename resolves to the
    // same file — deleting "original" would delete what we just wrote.)
    if ($original !== '' && strcasecmp($original, $name) !== 0) {
        $oldPath = script_path($original);
        if ($oldPath !== null && is_file($oldPath)) {
            unlink($oldPath);
        }
    }

    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'file' => $name]);
        exit;
    }
    header('Location: ?action=view&f=' . urlencode($name));
    exit;
}

// --- Load current file for view/edit ---------------------------------------
$content = '';
$path = $file !== '' ? script_path($file) : null;
if ($path !== null && is_file($path)) {
    $content = (string) file_get_contents($path);
} elseif ($action === 'new') {
    // A new script opens on its title block, because the credits are what a
    // writer stops recording once the writing starts — and on the format it
    // was started as. Choosing at the New button beats sniffing: the house
    // comic form (a bare "#" page, an unlabelled "##" panel, cue lines with no
    // marker) is ambiguous with Fountain sections by design, so an empty
    // document cannot be read, only declared.
    $content = $kind === 'comic'
        ? "Title: \nSeries: \nWriter: \nArtist: \nContact: \n\n# \n\n## \n"
        : "Title: \nCredit: written by\nAuthor: \nDraft date: " . date('Y-m-d') . "\nContact: \n";
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Splashline<?= $file !== '' ? ' · ' . h($file) : '' ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="?">Splashline</a>
  <nav>
    <a href="?action=new&amp;kind=screenplay">＋ Screenplay</a>
    <a href="?action=new&amp;kind=comic">＋ Comic</a>
    <?php if ($action === 'view' && $file !== ''): ?>
      <a href="?action=edit&f=<?= urlencode($file) ?>">Edit</a>
      <a href="#" onclick="window.print();return false">Print / PDF</a>
    <?php elseif ($action === 'edit' && $file !== ''): ?>
      <a href="?action=view&f=<?= urlencode($file) ?>">Preview</a>
    <?php endif; ?>
  </nav>
</header>

<main>
<?php if ($action === 'list'): ?>
  <section class="listing">
    <h2>Your scripts</h2>
    <?php $scripts = list_scripts(); ?>
    <?php if ($scripts === []): ?>
      <p class="empty">No scripts yet. Start a
        <a href="?action=new&amp;kind=screenplay">screenplay</a> or a
        <a href="?action=new&amp;kind=comic">comic script</a>.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($scripts as $s): ?>
          <li>
            <span>
              <a href="?action=view&f=<?= urlencode($s) ?>"><?= h($s) ?></a>
              <?php $head = (string) file_get_contents(SCRIPTS_DIR . '/' . $s, false, null, 0, 4096); ?>
              <span class="kind"><?= is_comic_file($s, $head) ? 'comic' : 'screen' ?></span>
            </span>
            <a class="muted" href="?action=edit&f=<?= urlencode($s) ?>">edit</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php elseif ($action === 'new' || $action === 'edit'): ?>
  <div class="write">
    <div class="write-bar">
      <input id="filename" class="filename" type="text"
             value="<?= h($file) ?>" placeholder="Untitled" autocomplete="off">
      <span id="status" class="status"></span>
    </div>

    <!-- The live-formatting surface. Its text content is always plain Fountain;
         editor.js styles each line as you type. -->
    <div id="editor" class="editor-live screenplay sheet" contenteditable="true"
         spellcheck="true" role="textbox" aria-multiline="true"></div>

    <!-- Initial Fountain source, handed to JS safely via a hidden textarea. -->
    <textarea id="source" hidden><?= h($content) ?></textarea>
    <input id="original" type="hidden" value="<?= h($file) ?>">
    <input id="kind" type="hidden" value="<?= h($kind) ?>">
  </div>
  <script src="editor.js"></script>

<?php elseif ($action === 'view' && $path !== null): ?>
  <?php
    if (is_comic_file($file, $content)) {
        echo (new ComicRenderer())->toHtml((new ComicParser())->parse($content));
    } else {
        echo (new Renderer())->toHtml((new FountainParser())->parse($content));
    }
  ?>
<?php else: ?>
  <p class="empty">Not found. <a href="?">Back to your scripts.</a></p>
<?php endif; ?>
</main>
</body>
</html>
