<?php

namespace yellowrobot\courier\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class EmailTemplateQuery extends ElementQuery
{
    public ?string $handle = null;
    public ?string $subject = null;

    public function handle(?string $value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function subject(?string $value): static
    {
        $this->subject = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('courier_templates');

        $this->query->addSelect([
            'courier_templates.handle',
            'courier_templates.subject',
            'courier_templates.htmlBody',
            'courier_templates.textBody',
            'courier_templates.bodyFile',
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('courier_templates.handle', $this->handle));
        }

        if ($this->subject !== null) {
            $this->subQuery->andWhere(Db::parseParam('courier_templates.subject', $this->subject));
        }

        return true;
    }
}
