<?php
namespace Electro\Plugins\Matisse\Services;

use Electro\Interfaces\Views\ViewServiceInterface;
use Electro\Plugins\Matisse\Components\Internal\DocumentFragment;
use Electro\Plugins\Matisse\Components\Macro\Macro;
use Electro\Plugins\Matisse\Exceptions\FileIOException;
use Electro\Plugins\Matisse\Exceptions\MatisseException;

/**
 * Manages macros loading, storage and retrieval.
 */
class MacrosService
{
  /**
   * Directories where macros can be found.
   * <p>They will be search in order until the requested macro is found.
   * <p>These paths will be registered on the templating engine.
   * <p>This is preinitialized to the application macro's path.
   *
   * @var string[]
   */
  public $macrosDirectories = [];
  /**
   * File extension of macro files.
   *
   * @var string
   */
  public $macrosExt = '.html';
  /**
   * @var ViewServiceInterface
   */
  private $viewService;

  public function __construct (ViewServiceInterface $viewService)
  {
    $this->viewService = $viewService;
  }

  /**
   * Searches for a file defining a macro for the given tag name.
   *
   * @param string $tagName
   * @param string $filename [optional] Outputs the filename that was searched for.
   * @return Macro
   * @throws MatisseException
   */
  function loadMacro ($tagName, &$filename = null)
  {
    $tagName  = normalizeTagName ($tagName);
    $filename = $tagName . $this->macrosExt;
    /** @var DocumentFragment $doc */
    $doc = $this->loadMacroFile ($filename);
    $c   = $doc->getFirstChild ();
    inspect (typeOf ($c));
    if ($c instanceof Macro)
      return $c;
    throw new MatisseException("File <path>$filename</path> doesn't define a macro called <kbd>$tagName</kbd>");
  }

  private function loadMacroFile ($filename)
  {
    foreach ($this->macrosDirectories as $dir) {
      $path = "$dir/$filename";
      if (file_exists ($path))
        return $this->viewService->loadFromFile ($path)->getCompiled ();
    }
    throw new FileIOException($filename);
  }

}
