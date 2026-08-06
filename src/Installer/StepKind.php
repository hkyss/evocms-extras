<?php

namespace hkyss\Extras\Installer;

enum StepKind: string
{
    case ManifestRequire = 'manifest.require';
    case ManifestRemove = 'manifest.remove';
    case ComposerRun = 'composer.run';
    case ProviderConfigDelete = 'provider.delete';

    case FileCopy = 'file.copy';
    case FileBackup = 'file.backup';
    case FileDelete = 'file.delete';
    case FileKeep = 'file.keep';

    case ElementUpsert = 'element.upsert';
    case ElementSkip = 'element.skip';
    case ElementDisable = 'element.disable';
    case ElementDelete = 'element.delete';

    case SqlApply = 'sql.apply';

    case RecordWrite = 'record.write';
    case RecordDelete = 'record.delete';

    public function mutates(): bool
    {
        return !in_array($this, [self::FileKeep, self::ElementSkip], true);
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }
}
