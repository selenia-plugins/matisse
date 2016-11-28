<?php
namespace Matisse\Services;

use Electro\Interfaces\Views\ViewServiceInterface;
use Matisse\Components\DocumentFragment;
use Matisse\Components\Macro\Macro;
use Matisse\Exceptions\FileIOException;
use Matisse\Exceptions\MatisseException;

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

  function findMacroFile ($tagName)
  {
    $tagName  = normalizeTagName ($tagName);
    $filename = $tagName . $this->macrosExt;
    foreach ($this->macrosDirectories as $dir) {
      $path = "$dir/$filename";
      if (file_exists ($path))
        return $path;
    }
    throw new FileIOException($filename);
  }

  /**
   * Loads and compiles the macro.
   *
   * <p>This method searches for a file defining a macro for the given tag name.
   * <p>It returns a DocumentFragment containing the macro as its first child.
   *
   * @param string $tagName
   * @param string $filename [optional] Outputs the filename that was searched for.
   * @return DocumentFragment
   * @throws MatisseException
   */
  function loadMacro ($tagName, &$filename = null)
  {
    $filename = $this->findMacroFile ($tagName);
    /** @var \Matisse\Components\DocumentFragment $doc */
    $doc = $this->loadMacroFile ($filename);
    $c   = $doc->getFirstChild ();
    if ($c instanceof Macro)
      return $doc;
    $filename = $this->findMacroFile ($filename);
    throw new MatisseException("File <path>$filename</path> doesn't define a macro called <kbd>$tagName</kbd> right at the beginning of the file");
  }

  private function loadMacroFile ($filename)
  {
    return $this->viewService->loadFromFile ($filename)->getCompiled ();
  }

}