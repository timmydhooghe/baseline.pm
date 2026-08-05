import type { ReactNode } from 'react';
import PositionLayout from '@/layouts/app/position-layout';
import type { BreadcrumbItem, EngagementPositionSummary } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    rail,
    position,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    rail?: ReactNode;
    position?: EngagementPositionSummary;
    children: ReactNode;
}) {
    return (
        <PositionLayout
            breadcrumbs={breadcrumbs}
            rail={rail}
            position={position}
        >
            {children}
        </PositionLayout>
    );
}
