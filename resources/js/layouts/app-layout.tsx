import type { ReactNode } from 'react';
import PositionLayout from '@/layouts/app/position-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    rail,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    rail?: ReactNode;
    children: ReactNode;
}) {
    return (
        <PositionLayout breadcrumbs={breadcrumbs} rail={rail}>
            {children}
        </PositionLayout>
    );
}
