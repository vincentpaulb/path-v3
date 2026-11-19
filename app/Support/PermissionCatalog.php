<?php

namespace App\Support;

class PermissionCatalog
{
    public static function modules(): array
    {
        return [
            'overview' => [
                'label' => 'Overview',
                'description' => 'Executive dashboard and proposal summary.',
                'icon' => 'fa-house',
                'permissions' => [
                    ['key' => 'dashboard.view', 'label' => 'View dashboard', 'action' => 'View'],
                ],
            ],
            'proposals' => [
                'label' => 'Proposals',
                'description' => 'Proposal records, document review, evaluators, collaboration, and proposal files.',
                'icon' => 'fa-file-lines',
                'permissions' => [
                    ['key' => 'proposals.view', 'label' => 'View proposal module', 'action' => 'View'],
                    ['key' => 'proposals.create', 'label' => 'Create proposals', 'action' => 'Add'],
                    ['key' => 'proposals.bulk_import', 'label' => 'Bulk import historical proposals', 'action' => 'Bulk import'],
                    ['key' => 'proposals.edit', 'label' => 'Edit proposal records', 'action' => 'Edit'],
                    ['key' => 'proposals.archive', 'label' => 'Move proposals to Archives', 'action' => 'Archive'],
                    ['key' => 'proposals.restore', 'label' => 'Restore archived proposals', 'action' => 'Restore'],
                    ['key' => 'proposals.document_checklist', 'label' => 'Manage Required Documents checklist on proposals', 'action' => 'Checklist'],
                    ['key' => 'proposals.missing_evaluation', 'label' => 'Add lacking-document evaluations', 'action' => 'Document review'],
                    ['key' => 'proposals.assign_evaluators', 'label' => 'Assign evaluators to proposals', 'action' => 'Evaluators'],
                    ['key' => 'proposals.manage_evaluator_status', 'label' => 'Manually update assigned evaluator status', 'action' => 'Evaluator status'],
                    ['key' => 'proposals.workflow', 'label' => 'Update MOA and Check workflow status', 'action' => 'Workflow'],
                    ['key' => 'proposals.comment', 'label' => 'Post comments and replies', 'action' => 'Comments'],
                    ['key' => 'proposals.download_files', 'label' => 'Download proposal files', 'action' => 'Download files'],
                    ['key' => 'proposals.upload_files', 'label' => 'Upload proposal files', 'action' => 'Upload files'],
                ],
            ],
            'funded' => [
                'label' => 'Funded Proposals',
                'description' => 'Funded-proposal queue and cheque/release processing.',
                'icon' => 'fa-money-bill-wave',
                'permissions' => [
                    ['key' => 'funded.view', 'label' => 'View funded proposals', 'action' => 'View'],
                    ['key' => 'funded.update_status', 'label' => 'Update funding / release status', 'action' => 'Update status'],
                ],
            ],
            'analytics' => [
                'label' => 'Analytics',
                'description' => 'Proposal analytics and program/province summaries.',
                'icon' => 'fa-chart-bar',
                'permissions' => [
                    ['key' => 'analytics.view', 'label' => 'View analytics', 'action' => 'View'],
                ],
            ],
            'monitoring' => [
                'label' => 'Monitoring',
                'description' => 'Overall monitoring workspaces for RTEC, rental performance, refund rates, and liquidation.',
                'icon' => 'fa-chart-line',
                'permissions' => [
                    ['key' => 'monitoring.rtec.view', 'label' => 'View RTEC Monitoring', 'action' => 'RTEC'],
                    ['key' => 'monitoring.rental.view', 'label' => 'View Rental Monitoring', 'action' => 'Rental'],
                    ['key' => 'monitoring.refund_rate.view', 'label' => 'View Refund Rate Monitoring', 'action' => 'Refund Rate'],
                    ['key' => 'monitoring.liquidation.view', 'label' => 'View Liquidation Monitoring', 'action' => 'Liquidation'],
                    ['key' => 'monitoring.liquidation.manage', 'label' => 'Update liquidation monitoring records', 'action' => 'Liquidation Manage'],
                ],
            ],
            'liquidation' => [
                'label' => 'Acquisition & Liquidation',
                'description' => 'Approved technology budgets, actual acquisitions, liquidation ledger, supporting documents, and financial review.',
                'icon' => 'fa-boxes-stacked',
                'permissions' => [
                    ['key' => 'liquidation.view', 'label' => 'View proposal acquisition & liquidation', 'action' => 'View'],
                    ['key' => 'liquidation.manage', 'label' => 'Manage approved items, acquisitions, ledger, and documents', 'action' => 'Manage'],
                    ['key' => 'liquidation.review', 'label' => 'Review liquidation, findings, and approval workflow', 'action' => 'Review'],
                ],
            ],
            'rental_records' => [
                'label' => 'Rental Records',
                'description' => '36-month refund/rental-payment monitoring attached to funded proposals.',
                'icon' => 'fa-receipt',
                'permissions' => [
                    ['key' => 'rental_records.view', 'label' => 'View rental records', 'action' => 'View'],
                    ['key' => 'rental_records.manage', 'label' => 'Record and edit rental payments', 'action' => 'Manage'],
                    ['key' => 'rental_records.bulk_import', 'label' => 'Bulk import proposal rental-record files from ZIP', 'action' => 'Bulk import'],
                ],
            ],
            'evaluator_portal' => [
                'label' => 'Evaluator Portal',
                'description' => 'Evaluator-only assigned-proposal workspace. Assignment scoping remains enforced.',
                'icon' => 'fa-clipboard-check',
                'permissions' => [
                    ['key' => 'evaluator_portal.view', 'label' => 'View assigned proposals', 'action' => 'View'],
                    ['key' => 'evaluator_portal.update_status', 'label' => 'Update own evaluation status', 'action' => 'Status'],
                    ['key' => 'evaluator_portal.remarks', 'label' => 'Add Forward / Return remarks', 'action' => 'Remarks'],
                ],
            ],
            'required_documents' => [
                'label' => 'Required Documents',
                'description' => 'Master Required Documents checklist management.',
                'icon' => 'fa-list-check',
                'permissions' => [
                    ['key' => 'required_documents.view', 'label' => 'View master checklist', 'action' => 'View'],
                    ['key' => 'required_documents.create', 'label' => 'Add required documents', 'action' => 'Add'],
                    ['key' => 'required_documents.edit', 'label' => 'Edit required documents', 'action' => 'Edit'],
                    ['key' => 'required_documents.archive', 'label' => 'Move required documents to Archives', 'action' => 'Archive'],
                    ['key' => 'required_documents.restore', 'label' => 'Restore required documents', 'action' => 'Restore'],
                ],
            ],
            'industry_sectors' => [
                'label' => 'Industry Sectors',
                'description' => 'Industry classifications used by the Proposal Code generator.',
                'icon' => 'fa-industry',
                'permissions' => [
                    ['key' => 'industry_sectors.view', 'label' => 'View industry-sector master data', 'action' => 'View'],
                    ['key' => 'industry_sectors.create', 'label' => 'Add industry sectors', 'action' => 'Add'],
                    ['key' => 'industry_sectors.edit', 'label' => 'Edit industry-sector details', 'action' => 'Edit'],
                    ['key' => 'industry_sectors.toggle', 'label' => 'Activate or deactivate industry sectors', 'action' => 'Status'],
                    ['key' => 'industry_sectors.delete', 'label' => 'Delete unused industry sectors', 'action' => 'Delete'],
                ],
            ],
            'evaluators' => [
                'label' => 'Evaluators',
                'description' => 'Evaluator directory and portal-account management.',
                'icon' => 'fa-user-check',
                'permissions' => [
                    ['key' => 'evaluators.view', 'label' => 'View evaluator directory', 'action' => 'View'],
                    ['key' => 'evaluators.create', 'label' => 'Add evaluators', 'action' => 'Add'],
                    ['key' => 'evaluators.edit', 'label' => 'Edit evaluators', 'action' => 'Edit'],
                    ['key' => 'evaluators.archive', 'label' => 'Move evaluators to Archives', 'action' => 'Archive'],
                    ['key' => 'evaluators.restore', 'label' => 'Restore evaluators', 'action' => 'Restore'],
                ],
            ],
            'provinces' => [
                'label' => 'Provinces',
                'description' => 'Province master data used by proposal, user, analytics, and monitoring workflows.',
                'icon' => 'fa-map-location-dot',
                'permissions' => [
                    ['key' => 'provinces.view', 'label' => 'View province master data', 'action' => 'View'],
                    ['key' => 'provinces.create', 'label' => 'Add provinces', 'action' => 'Add'],
                    ['key' => 'provinces.edit', 'label' => 'Edit province details', 'action' => 'Edit'],
                    ['key' => 'provinces.toggle', 'label' => 'Activate or deactivate provinces', 'action' => 'Status'],
                    ['key' => 'provinces.delete', 'label' => 'Delete unused provinces', 'action' => 'Delete'],
                ],
            ],
            'users' => [
                'label' => 'User Management',
                'description' => 'PATH login accounts and role assignment.',
                'icon' => 'fa-users',
                'permissions' => [
                    ['key' => 'users.view', 'label' => 'View users', 'action' => 'View'],
                    ['key' => 'users.create', 'label' => 'Add users', 'action' => 'Add'],
                    ['key' => 'users.edit', 'label' => 'Edit users', 'action' => 'Edit'],
                    ['key' => 'users.archive', 'label' => 'Move users to Archives', 'action' => 'Archive'],
                    ['key' => 'users.restore', 'label' => 'Restore users', 'action' => 'Restore'],
                ],
            ],
            'archives' => [
                'label' => 'Archives',
                'description' => 'Open the segregated Archives area. Restore rights are controlled by each module above.',
                'icon' => 'fa-box-archive',
                'permissions' => [
                    ['key' => 'archives.view', 'label' => 'View Archives', 'action' => 'View'],
                ],
            ],
        ];
    }

    public static function allPermissions(): array
    {
        $permissions = [];
        $sort = 10;

        foreach (self::modules() as $moduleKey => $module) {
            foreach ($module['permissions'] as $permission) {
                $permissions[] = [
                    'key' => $permission['key'],
                    'module' => $moduleKey,
                    'action' => $permission['action'],
                    'label' => $permission['label'],
                    'sort_order' => $sort,
                ];
                $sort += 10;
            }
        }

        return $permissions;
    }

    public static function defaultRolePermissions(): array
    {
        $all = array_column(self::allPermissions(), 'key');

        return [
            'Admin' => $all,
            'Regular' => [
                'dashboard.view', 'proposals.view', 'proposals.comment',
                'proposals.download_files', 'analytics.view', 'rental_records.view',
            ],
            'TOD' => [
                'dashboard.view', 'proposals.view', 'proposals.create', 'proposals.edit',
                'proposals.document_checklist', 'proposals.missing_evaluation', 'proposals.assign_evaluators', 'proposals.manage_evaluator_status',
                'proposals.workflow', 'proposals.comment', 'proposals.download_files', 'proposals.upload_files',
                'funded.view', 'analytics.view', 'rental_records.view', 'rental_records.manage',
                'liquidation.view', 'liquidation.manage', 'liquidation.review',
                'required_documents.view', 'required_documents.create', 'required_documents.edit',
                'required_documents.archive', 'required_documents.restore', 'archives.view',
            ],
            'PSTO' => [
                'dashboard.view', 'proposals.view', 'proposals.comment', 'proposals.download_files',
                'funded.view', 'analytics.view',
                'monitoring.rtec.view', 'monitoring.rental.view',
                'monitoring.refund_rate.view', 'monitoring.liquidation.view',
                'rental_records.view', 'liquidation.view',
            ],
            'Head' => [
                'dashboard.view', 'proposals.view', 'proposals.comment',
                'proposals.download_files', 'analytics.view', 'rental_records.view', 'liquidation.view',
            ],
            'Evaluator' => [
                'dashboard.view', 'analytics.view', 'evaluator_portal.view',
                'evaluator_portal.update_status', 'evaluator_portal.remarks',
                'proposals.comment', 'proposals.download_files',
            ],
            'Cashier' => [
                'dashboard.view', 'funded.view', 'funded.update_status', 'proposals.comment', 'proposals.download_files',
                'rental_records.view', 'rental_records.manage', 'liquidation.view',
            ],
        ];
    }

    public static function pstoRequiredPermissions(): array
    {
        return [
            'proposals.view',
            'proposals.comment',
            'monitoring.rtec.view',
            'monitoring.rental.view',
            'monitoring.refund_rate.view',
            'monitoring.liquidation.view',
        ];
    }

    public static function pstoRestrictedPermissions(): array
    {
        return [
            'proposals.create',
            'proposals.bulk_import',
            'proposals.edit',
            'proposals.archive',
            'proposals.restore',
            'proposals.document_checklist',
            'proposals.missing_evaluation',
            'proposals.assign_evaluators',
            'proposals.manage_evaluator_status',
            'proposals.workflow',
            'proposals.upload_files',
            'funded.update_status',
            'monitoring.liquidation.manage',
            'liquidation.manage',
            'liquidation.review',
            'rental_records.manage',
            'rental_records.bulk_import',
        ];
    }

    public static function roleDefinitions(): array
    {
        return [
            ['name' => 'Admin', 'slug' => 'admin', 'description' => 'Full PATH administration and security control.', 'data_scope' => 'global', 'is_system' => true, 'is_protected' => true],
            ['name' => 'Regular', 'slug' => 'regular', 'description' => 'General read and collaboration access.', 'data_scope' => 'global', 'is_system' => true, 'is_protected' => false],
            ['name' => 'TOD', 'slug' => 'tod', 'description' => 'Technical Operations Division proposal administration.', 'data_scope' => 'global', 'is_system' => true, 'is_protected' => false],
            ['name' => 'PSTO', 'slug' => 'psto', 'description' => 'Provincial S&T Office access restricted to the assigned province.', 'data_scope' => 'province', 'is_system' => true, 'is_protected' => false],
            ['name' => 'Head', 'slug' => 'head', 'description' => 'Management review and analytics access.', 'data_scope' => 'global', 'is_system' => true, 'is_protected' => false],
            ['name' => 'Evaluator', 'slug' => 'evaluator', 'description' => 'Evaluator portal restricted to explicitly assigned proposals.', 'data_scope' => 'assigned', 'is_system' => true, 'is_protected' => false],
            ['name' => 'Cashier', 'slug' => 'cashier', 'description' => 'Funded-proposal processing and cheque/release status.', 'data_scope' => 'global', 'is_system' => true, 'is_protected' => false],
        ];
    }
}
