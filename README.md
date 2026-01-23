# Copy-Paste Detector

An AST-based structural code clone detector for PHP, inspired by the CloneDR methodology. This tool efficiently detects Type-2 (parameterized) code clones using AST analysis and hash-based exact matching.

## Installation

```bash
composer require --dev shipmonk/copy-paste-detector
```

## Usage

### Basic Usage

Detect clones in a directory:

```bash
php bin/copy-paste-detector src/
```

Detect clones in multiple directories:

```bash
php bin/copy-paste-detector src/ tests/
```

Detect clones in a single file:

```bash
php bin/copy-paste-detector path/to/file.php
```

### Configuration Options

#### Command-Line Options

```bash
php bin/copy-paste-detector [<path>...] [options]
```

Paths are optional if configured in the config file.

**Options:**

- `--config=FILE` or `-c FILE`: Path to configuration file (default: `copy-paste-detector.php` in current directory)
  - Configuration file must return a `CopyPasteDetector\Config\Config` instance
  - See [Configuration File](#configuration-file) section below

- `--min-node-count=N` or `-m N`: Minimum number of AST nodes for a subtree to be considered (default: 50)
  - Higher values = fewer, larger clones
  - Lower values = more, smaller clones
  - Overrides config file setting

- `--cache-dir=DIR`: Directory for caching parsed subtrees (default: system temp directory)
  - Overrides config file setting

#### Configuration File

Create a `copy-paste-detector.php` file in your project root to configure detection settings:

```php
<?php

use CopyPasteDetector\Config\Config;

$config = new Config();

// Set paths to analyze (can be overridden by CLI arguments)
$config->setPaths(['src/', 'tests/']);
// Or add paths individually:
// $config->addPath('src/');
// $config->addPath('tests/');

// Set the minimum node count for clone detection
$config->setMinNodeCount(50);

// Set cache directory (optional, defaults to system temp directory)
$config->setCacheDir('cache');

// Configure anonymization strategies (see below)
$config->setAnonymizeVariables(true);
$config->setAnonymizeLiterals(false);
$config->setAnonymizeNames(false);
$config->setAnonymizeIdentifiers(false);

return $config;
```

#### Anonymization Strategies

The tool supports different anonymization strategies to control what is considered "equivalent" when detecting clones:

- **Variables** (`setAnonymizeVariables`, default: `true`): When enabled, variable names like `$foo`, `$bar` are treated as equivalent
  ```php
  // These would be detected as clones:
  $result = $items * 2;
  $total = $values * 2;
  ```

- **Literals** (`setAnonymizeLiterals`, default: `false`): When enabled, string and numeric literals are treated as equivalent
  ```php
  // These would be detected as clones (with literals enabled):
  echo "Hello";
  echo "World";
  ```

- **Names** (`setAnonymizeNames`, default: `false`): When enabled, function and class names are treated as equivalent
  ```php
  // These would be detected as clones (with names enabled):
  calculateSum($items);
  processValues($data);
  ```

- **Identifiers** (`setAnonymizeIdentifiers`, default: `false`): When enabled, method and constant names are treated as equivalent
  ```php
  // These would be detected as clones (with identifiers enabled):
  $obj->getValue();
  $obj->getTotal();
  ```

**Recommended Settings:**

For most use cases, keep the default settings (only variables anonymized). This provides a good balance between detecting real duplication and avoiding false positives from common patterns.

Enable additional anonymizations if you want to detect more structural similarities, but be prepared for more false positives from design patterns.

### Examples

Use default configuration file:

```bash
php bin/copy-paste-detector src/
```

Use a custom configuration file:

```bash
php bin/copy-paste-detector src/ --config=my-config.php
```

Find large clones (override config file):

```bash
php bin/copy-paste-detector src/ --min-node-count=100
```

Find smaller clones (more sensitive):

```bash
php bin/copy-paste-detector src/ --min-node-count=30
```

## Understanding the Results

The tool reports clone groups with:

- **Instance Count**: Number of times the identical code structure appears in your codebase
- **Node Count**: Number of AST nodes in the cloned subtree
- **Location**: File paths and line numbers for each clone instance
- **Code Snippets**: Actual code for each instance

Example output:

```
Clone Group #1 (52 nodes, 3 instances)
--------------------------------------------------------------------------------
src/Calculator.php:
  15 | function calculateSum($items) {
  16 |     $total = 0;
  17 |     foreach ($items as $item) {
  ...

src/Processor.php:
  30 | function processValues($values) {
  31 |     $result = 0;
  32 |     foreach ($values as $value) {
  ...

src/Validator.php:
  45 | function validateData($data) {
  46 |     $count = 0;
  47 |     foreach ($data as $item) {
  ...
```

## Known Limitations

This hash-based approach detects **exact structural clones** and has specific limitations:

### Expected Limitations (By Design)

- **Exact Matching Only**: Only detects exact structural matches after normalization (Type-2 clones). Near-miss clones with minor differences are not detected.
- **Variable Names Only**: By default, only variable names are anonymized. Function names, class names, method names, and literal values must match exactly.
- **Statement Reordering**: Swapping independent statements produces different ASTs (not detected as clones)
- **Loop Transformations**: Converting `for` to `while` changes the AST structure (not detected)
- **Control Flow Changes**: Equivalent logic with different control flow structures won't match

### False Positives from Design Patterns

The parameterization (ignoring variable names) can cause false positives when code shares similar **structural patterns** but different semantics:

- **Visitor Pattern Implementations**: Methods like `enterNode()` with similar structure
- **Constructor Patterns**: Classes with similar initialization logic
- **Getter/Setter Patterns**: Similar accessor method structures
- **Iterator Patterns**: Similar loop structures processing different data
- **Boilerplate Code**: Repetitive but intentional patterns (e.g., multiple similar test cases)

### Recommended Threshold Tuning

To minimize false positives while catching real duplications:

**For production codebases:**
- `--min-node-count=70-100` (focuses on substantial code blocks, reduces pattern noise)
- Review results manually to identify genuine duplication vs. acceptable patterns

**For finding aggressive duplication:**
- `--min-node-count=50` (default - good balance)

**For exploratory analysis:**
- `--min-node-count=30` (more sensitive, expect more false positives from patterns)
- Best for initial codebase assessment

## Development

### Running Tests

```bash
vendor/bin/phpunit
```

### Static Analysis

The project uses PHPStan at level 6 for static analysis:

```bash
# Run PHPStan
vendor/bin/phpstan analyze
```

### Project Structure

```
src/
├── AST/              # AST parsing and subtree extraction
├── Cache/            # Subtree caching for performance
├── Hashing/          # AST normalization and MD5 hashing
├── Detection/        # Clone detection and grouping
├── Reporting/        # Report generation and syntax highlighting
├── CLI/              # Command-line interface
└── Config/           # Configuration management

tests/
├── *Test.php         # Unit and integration tests
└── fixtures/         # Test files with known clones
```

## Algorithm Details

### AST Normalization

Each subtree undergoes normalization to enable Type-2 clone detection:

1. **Deep Clone**: The AST subtree is deep-cloned to avoid modifying the original
2. **Variable Anonymization**: Variable names are replaced with position-based identifiers
   - `$foo` → `$V0`
   - `$bar` → `$V1`
   - `$items` → `$V2`
3. **Other Identifiers Preserved**: Function names, class names, method names, and literals remain unchanged

This normalization ensures that code with identical structure but different variable names will produce the same hash.

### Hash-Based Exact Matching

After normalization:

1. **Serialization**: The normalized AST is serialized to a canonical string representation
   - Includes node types and structure
   - Excludes line numbers and position metadata
2. **MD5 Hashing**: The serialized string is hashed using MD5 to create a 32-character fingerprint
3. **Grouping**: All subtrees with identical MD5 hashes are grouped together
4. **Performance**: This approach is O(N) where N is the number of subtrees, avoiding expensive pairwise comparisons

### Why Hash-Based (Not LSH)?

This implementation uses exact hash matching rather than Locality Sensitive Hashing (LSH) because:

- **Simplicity**: Direct hash comparison is simpler and easier to understand
- **Performance**: MD5 hashing is very fast, and exact matching requires no similarity calculations
- **Precision**: No false positives from hash collisions (MD5 collisions are extremely rare)
- **Trade-off**: Cannot detect near-miss clones (Type-3), only exact structural matches (Type-2)

## Requirements

- PHP 8.1 or higher
- Composer

## License

MIT

## Credits

Inspired by the CloneDR code clone detection methodology, which uses hash-based exact matching of normalized AST subtrees.
