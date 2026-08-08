import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Fragment } from 'react';

export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb>
                    <BreadcrumbList>
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;
                            // A phone header has no room for the whole trail — the
                            // current page alone survives, the ancestors return at sm.
                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem className={isLast ? 'min-w-0' : 'hidden sm:inline-flex'}>
                                        {isLast ? (
                                            <BreadcrumbPage className="truncate">{item.title}</BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink href={item.href}>{item.title}</BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && <BreadcrumbSeparator className="hidden sm:block" />}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
