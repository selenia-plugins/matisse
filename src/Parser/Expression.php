<?php

namespace Matisse\Parser;

use Electro\Traits\InspectionTrait;
use Matisse\Exceptions\DataBindingException;
use Matisse\Interfaces\DataBinderInterface;
use PhpCode;
use RuntimeException;

/**
 * Represents a Matisse databinding expression.
 *
 * <p>An instance of this class represents one expression and it provides functionality for:
 * - parsing it;
 * - translating it to PHP source code;
 * - compiling it to native code.
 *
 * ### Databinding expressions features and syntax
 * An expression is composed of two parts:
 * - the main expression;
 * - a sequence of filter sub-expressions, each one prefixed by the pipe (`|`) operator.
 *
 * The main expression is similar to a PHP expression, but with the following differences:
 * - The dot (`.`) operator provides access to a property, array index, or getter function of the left operand.
 *   The right operand must be an unquoted symbol.<br>
 *   Ex: `'obj.prop1.prop2'`
 * - The first element of a dot-delimited sequence is searched for in a stack of nested view models, starting on the
 *   current component.
 * - Consecutive segments are evaluated in a way that is resilient to errors; dereferencing null or accessing
 *   non-existing indexes or properties is valid and evaluates to null, which displays as an empty string.
 * - The plus (`+`) operator concatenates strings. This means you cannot use it to perform numerical calculations.
 *   If you need them, pre-compute the values on the controller (that's the right place to do it).
 * - `'#block'` evaluates to the rendering of the specified block; it cannot be followed by a dot.
 * - '&#64;prop' evaluates to the specified property of the current composite component container.
 *
 * ### Constants
 * Expressions can contain one or more constants between operators.
 * <p>Valid constants are:
 *   - 123 or 123.4
 *   - `"string"` or `'string'`
 *   - `true, false, null`
 *   - any PHP constant defined via `define()` or `const`
 *   - `namespace\class::constant` - note: `self::` or `static::` are not valid.
 *
 * ### Static method calls
 * A static method reference has the following syntax:
 *   - `namespace\class::method` - note: `self::` or `static::` are not valid).
 *
 * ### Filters
 * Syntax: `filterName arg1,...argN`
 *
 * <p>Ex:
 * ```
 *   "obj.prop | then 'true','false'"
 *   "onj.prop | format '%.3f' | json"
 * ```
 */
class Expression implements \Serializable
{
  use InspectionTrait;

  /**
   * The name of the parameter of type DataBinder used on an compiled function.
   */
  const BINDER_PARAM = '$b';
  /**
   * Finds binding expressions and extracts information from them.
   * > Note: the u modifier allows unicode white space to be properly matched.
   */
  const PARSE_BINDING_EXP = '/
    \{              # opens with {
    \x20*           # ignore spaces (but not line breaks)
    (?= \S)         # assert that next char is not white space
    (               # begin capture
      (?:           # begin loop
        (?! \s*\})  # if not white space followed by a }
        .           # consume character
      )*            # repeat
    )               # end capture
    \s*             # ignore white space
    \}              # closes with }
  /sxu';
  /**
   * Finds binding expressions.
   * > Note: this reg.exp. has no delimiters so that it may be embedded on another reg.exp. (with sxu modifiers)
   */
  const PARSE_BINDING_EXP_INNER = '
    \{              # opens with {
    \x20*           # ignore spaces (but not line breaks)
    (?= \S)         # assert that next char is not white space
    (?:             # begin loop
      (?! \s*\})    # if not white space followed by a }
      .             # consume character
    )*              # repeat
    \s*             # ignore white space
    \}              # closes with }
';
  /**
   * Splits the filters part of an expression into a sequential list of filter expressions.
   */
  const PARSE_FILTER = '/\s*(?<!\|)\|(?!\|)\s*/';
  /**
   * Extracts a simple expression segment from a dot-delimited list of segments.
   * <p>Ex: `'a.b.c'`
   */
  const PARSE_SIMPLE_EXPR = <<<'REGEXP'
/
  ^\s*                # ignore white space at the beginning
  (                   # capture either
    !*[@#]?[\w:\\\\]+ # a constant name, a property name (ex: "prop", "@prop"), a block name (ex: "#prop") or a class
                      # constant or static method (ex: Namespace\SomeClass::someConstant or Namespace\SomeClass::someMethod)
    |                 # or
    '(?:\\'|[^'])*'   # a quoted string constant (supports escaped quotes inside the string)
    |                 # or
    "(?:\\"|[^'])*"   # a double quoted string constant (supports escaped double quotes inside the string)
  )
  \s*                 # ignore white space
  (                   # capture the next operator, if one is present, including the the first filter\'s pipe
    \|(?!\|)          # capture the pipe operator (but not the || operator)
    |                                     # or
    [,\|\.\/\-\(\)\[\]\{\}%&=\?\*\+<>!]+  # one of the other allowed operators
  )?
/xu
REGEXP;
  public static $INSPECTABLE = ['expression', 'translated'];
  /**
   * A map of databinding expressions to compiled PHP code.
   *
   * @var \Closure[]
   */
  public static $cache = [];
  /**
   * A map of databinding expressions to pre-compiled PHP expressions.
   *
   * ><p>This is meant for debugging only.
   *
   * @var string[] [string => string]
   */
  public static $translationCache = [];
  /**
   * @var \Closure|null A function that receives a context argument and returns the evaluated value. Read-only.
   */
  public $compiled = null;
  /**
   * The original, unparsed, expression.
   *
   * <p>To read this, cast the instance to `string` or call {@see __toString()}.
   *
   * @var string
   */
  public $expression;
  /**
   * @var string|null The original expression translated to PHP code. Read-only.
   */
  public $translated = null;

  function __construct ($expression)
  {
    if (!preg_match (self::PARSE_BINDING_EXP, $expression, $matches))
      throw new DataBindingException("Invalid databinding expression: $expression");

    list ($full, $this->expression) = $matches;
  }

  public static function isBindingExpression ($exp)
  {
    return is_string ($exp) && strlen ($exp) > 2 ? $exp[0] == '{' && substr ($exp, -1) == '}' : false;
  }

  /**
   * Pre-compiles the given simple binding expression.
   *
   * <p>Simple expressions do not have operators or filters. They are comprised of constants or property access chains
   * only.
   *
   * <p>**Ex:** `'a.b.c'`, `'123'`, `'"text"'`, `'false'`, `'Class::constant'`, '&#64;prop', `'#block'`.
   *
   * > <p>**Note:** simple expressions are used on the main part of a databinding expression and as expression filter
   * arguments.
   *
   * @param string[] $segments Expression segments split by dot.
   * @return string
   * @throws DataBindingException
   */
  static function translateSimpleExpSegs (array $segments)
  {
    if (count ($segments) == 1) {
      $seg = $segments[0];

      if ($seg[0] == '#')
        return sprintf ('%s->renderBlock("%s")', self::BINDER_PARAM, substr ($seg, 1));

      PhpCode::evalConstant ($seg, $ok);
      if ($ok) return $seg;
      if (is_callable ($seg))
        return "$seg()";
    }

    $exp   = self::BINDER_PARAM;
    $unary = '';
    foreach ($segments as $i => $seg) {
      if ($i)
        $exp = $seg[0] == '@'
          ? sprintf ('_g(%s,%s)', $exp, self::compileReadProp ($seg))
          : "_g($exp, '" . trim ($seg, "'\"") . "')";
      else {
        list (, $unary, $seg) = str_match ($seg, '/^(!*)(.*)/', 2);
        // If not a constant value, convert it to a property access expression fragment.
        if ($seg[0] == '"' || $seg[0] == "'" || ctype_digit ($seg))
          $exp = $seg;
        else $exp = $seg[0] == '@'
          ? self::compileReadProp ($seg)
          : $exp = "_g($exp,'$seg')";
      }
    }
    $exp = "$unary$exp";
    if (!PhpCode::validateExpression ($exp))
      throw new DataBindingException(sprintf ("Invalid expression <kbd>%s</kbd>.<p>Evaluating <kbd>%s</kbd></p>", implode ('.', $segments), $exp));
    return $exp;
  }

  /**
   * Computes the expression's value applied on the context of the given component.
   *
   * <p>This automatically compiles and caches the expression, if it's not already so.
   *
   * @param DataBinderInterface $binder The data binder that provides a data context as a starting point for resolving
   *                                    property accesses.
   * @return mixed
   * @throws DataBindingException
   */
  function __invoke (DataBinderInterface $binder)
  {
    if (!($fn = $this->compiled)) {
      $exp = $this->expression;
      $fn  = get (self::$cache, $exp);
      if ($fn) {
        $this->translated = self::$translationCache[$exp];
        $this->compiled   = $fn;
      }
      else {
        // translate to PHP.
        if (!$this->translated) // if the expression was unserialized, this will already be set
          $this->translated = self::translate ($exp);
        // Compile to native code.
        try {
          $fn = $this->compiled = PhpCode::compile ($this->translated,
            sprintf ('%s %s',
              DataBinderInterface::class, self::BINDER_PARAM
            ));
        }
        catch (RuntimeException $e) {
          self::filterSyntaxError ($exp, '<hr>' . $e->getMessage ());
        }
        // Cache the compiled expression.
        self::$cache[$exp]            = $fn;
        self::$translationCache[$exp] = $this->translated;
      }
    }
    return $fn ($binder);
  }

  /**
   * Returns the original, unparsed, expression.
   *
   * @return string
   */
  function __toString ()
  {
    return "{{$this->expression}}";
  }

  public function serialize ()
  {
    return serialize ([$this->expression, $this->translated ?: self::translate ($this->expression)]);
  }

  public function unserialize ($serialized)
  {
    list ($this->expression, $this->translated) = unserialize ($serialized);
  }

  static private function compileReadProp ($prop)
  {
    return sprintf ("_g(_g(%s,'props'),'%s')", self::BINDER_PARAM, substr ($prop, 1));
  }

  /**
   * Throws a filter error.
   *
   * @param string $filter
   * @param string $message
   * @throws DataBindingException
   */
  static private function filterSyntaxError ($filter, $message)
  {
    throw new DataBindingException ("<h5>Filter Syntax Error</h5><p>Expression: <kbd>$filter</kbd><p>$message");
  }

  /**
   * Translates a databinding expression to PHP source code.
   *
   * @param string $expression
   * @return string
   * @throws DataBindingException
   */
  static private function translate ($expression)
  {
    list ($main, $op) = self::translateSimpleExpression ($expression);

    $exp = $main;
    if ($op == '|') {
      $filters = preg_split (self::PARSE_FILTER, $expression);
      if ($filters)
        foreach ($filters as $filter)
          $exp = self::translateFilter ($filter, $exp);
    }

    return $exp;
  }

  /**
   * Precompiles a single filter sub-expression, composed of a filter name and a list of optional comma-delimited
   * arguments.
   *
   * @param string $filter Rhe filter expression.
   * @param string $input  The implicit input to the filter (the sub-expression before the pipe)
   * @return string
   * @throws DataBindingException
   */
  static private function translateFilter ($filter, $input)
  {
    // Filter expressions syntax: filter arg1,...argN

    if (preg_match ('/^\S+\s*?\(/', $filter))
      self::filterSyntaxError ($filter, "Filter arguments must not be enclosed in <kbd>()</kbd>");
    list ($name, $argsStr) = str_extractSegment ($filter, '/\s+/');
    $args = [];

    while ($argsStr !== '') {
      list ($subExp, $op) = self::translateSimpleExpression ($argsStr);
      if ($op && $op != ',')
        self::filterSyntaxError ($filter, "Filter arguments must be simple expressions; operators are not allowed.
<p>Offending character: <kbd>$op</kbd>");
      $args[] = $subExp;
    }

    if ($name == '*') {
      if ($args) self::filterSyntaxError ($filter, "Raw output filter function <kbd>*</kbd> must have no arguments.");
      return "(new RawText($input))";
    }
    return sprintf ('%s->filter(\'%s\',%s%s%s)', self::BINDER_PARAM, addslashes ($name), $input, $args ? ',' : '',
      implode (',', $args));
  }

  /**
   * @param string $expression [reference] The parsed sub-expression will be removed from the input expression.
   * @return string[] The extracted sub-expression and the next operator (if any).
   * @throws DataBindingException
   */
  static private function translateSimpleExpression (& $expression)
  {
    $op         = $subExp = '';
    $segs       = [];
    $expression = trim ($expression);
    while ($expression !== '' && preg_match (self::PARSE_SIMPLE_EXPR, $expression, $m)) {
      $m[] = '';
      list ($all, $seg, $op) = $m;
      $expression = trim (substr ($expression, strlen ($all)));
      $segs[]     = $seg;
      if ($op == '.') continue;
      if ($op == '|' || $op == ',') break;
      // Use + to concatenate strings, except for `number+any` expressions (ex: 1+page).
      if ($op == '+' && !is_numeric ($seg)) $op = '.';
      $subExp .= self::translateSimpleExpSegs ($segs) . $op;
      $segs   = [];
    }
    if ($segs)
      $subExp .= self::translateSimpleExpSegs ($segs);
    return [$subExp, $op];
  }

}
