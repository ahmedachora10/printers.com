/** تاسك 115: صفٌّ من سجلّ النشاط كما يشكّله ActivityResource. */
export interface ActivityChange {
    field: string;
    label: string;
    old: string;
    new: string;
}

/** مفتاحٌ وقيمة بلا مقابلٍ قديم — مبلغ حسمٍ محذوف، عدد المصروفات المعتمَدة، السبب. */
export interface ActivityDetail {
    label: string;
    value: string;
}

export interface ActivityEntry {
    id: number;
    logName: string | null;
    logLabel: string | null;
    /** الفعل بالعربية: أنشأ / عدّل / اعتمد / سجّل الدخول … */
    action: string;
    causerId: number | null;
    causerName: string;
    /** نوع السجلّ المتأثّر — فارغ للأحداث التي لا سجلَّ لها (كالدخول). */
    subjectType: string | null;
    subjectLabel: string | null;
    subjectUrl: string | null;
    /** YYYY-MM-DD */
    date: string;
    /** HH:mm */
    time: string;
    /** DD/MM/YYYY HH:mm */
    at: string;
    changes: ActivityChange[];
    details: ActivityDetail[];
    /** عملية تمسّ المال أو الحالة أو الصلاحية — تُميَّز بصرياً للمراجعة. */
    isSensitive: boolean;
}

export interface ActivityFilters {
    from: string;
    to: string;
    log: string;
    search: string;
    user: string;
}

export interface ActivitySubject {
    id: number;
    name: string;
    username: string | null;
    roleLabel: string | null;
    branchName: string | null;
    isActive: boolean;
}

export interface PagedActivities {
    data: ActivityEntry[];
    meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null };
}
