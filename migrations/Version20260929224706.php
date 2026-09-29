<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Golden Gate tables: API keys used by client applications and the immutable
 * usage record written for every gateway request.
 */
final class Version20260929224706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_key and usage_record tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE api_key_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE usage_record_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE api_key (name VARCHAR(120) NOT NULL, key_prefix VARCHAR(32) NOT NULL, key_hash VARCHAR(64) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, monthly_request_limit INT DEFAULT NULL, monthly_token_limit BIGINT DEFAULT NULL, monthly_budget_micro_usd BIGINT DEFAULT NULL, allowed_models JSON DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id INT NOT NULL, uuid UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C912ED9DD17F50A6 ON api_key (uuid)');
        $this->addSql('CREATE INDEX idx_api_key_prefix ON api_key (key_prefix)');
        $this->addSql('CREATE TABLE usage_record (api_key_prefix VARCHAR(32) NOT NULL, request_id VARCHAR(64) NOT NULL, model_alias VARCHAR(120) NOT NULL, provider VARCHAR(60) NOT NULL, upstream_model VARCHAR(160) NOT NULL, status VARCHAR(16) NOT NULL, error_category VARCHAR(64) DEFAULT NULL, prompt_tokens INT DEFAULT NULL, completion_tokens INT DEFAULT NULL, total_tokens BIGINT DEFAULT NULL, estimated_cost_micro_usd BIGINT DEFAULT 0 NOT NULL, latency_ms INT DEFAULT NULL, provider_request_id VARCHAR(190) DEFAULT NULL, provider_model VARCHAR(190) DEFAULT NULL, id INT NOT NULL, uuid UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, api_key_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1BB8EE9D17F50A6 ON usage_record (uuid)');
        $this->addSql('CREATE INDEX IDX_C1BB8EE98BE312B3 ON usage_record (api_key_id)');
        $this->addSql('CREATE INDEX idx_usage_record_key_created ON usage_record (api_key_id, created_at)');
        $this->addSql('CREATE INDEX idx_usage_record_created ON usage_record (created_at)');
        $this->addSql('ALTER TABLE usage_record ADD CONSTRAINT FK_C1BB8EE98BE312B3 FOREIGN KEY (api_key_id) REFERENCES api_key (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE api_key_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE usage_record_id_seq CASCADE');
        $this->addSql('ALTER TABLE usage_record DROP CONSTRAINT FK_C1BB8EE98BE312B3');
        $this->addSql('DROP TABLE api_key');
        $this->addSql('DROP TABLE usage_record');
    }
}
