import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import { ArrowLeftIcon, CheckIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/nursery/PageHeader';

// One labelled field with inline validation error.
function Field({ label, error, required, children }) {
    return (
        <div className="space-y-1.5">
            <Label>
                {label}
                {required && <span className="text-danger"> *</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-danger">{error.message}</p>}
        </div>
    );
}

// A titled group of fields — keeps long master forms scannable.
function Section({ title, description, children }) {
    return (
        <section className="rounded-lg border border-border bg-card p-5">
            <div className="mb-4">
                <h2 className="text-sm font-semibold text-foreground">{title}</h2>
                {description && (
                    <p className="text-xs text-muted-foreground">{description}</p>
                )}
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
        </section>
    );
}

export default function SupplierForm({ team, supplier, statusOptions = [] }) {
    const record = supplier?.data ?? supplier ?? null;
    const isEdit = Boolean(record);
    const base = `/${team}/suppliers`;

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isDirty },
    } = useForm({
        defaultValues: {
            supplier_code: record?.supplier_code ?? '',
            name: record?.name ?? '',
            email: record?.email ?? '',
            phone: record?.phone ?? '',
            gst_number: record?.gst_number ?? '',
            pan_number: record?.pan_number ?? '',
            address_line1: record?.address_line1 ?? '',
            address_line2: record?.address_line2 ?? '',
            city: record?.city ?? '',
            state: record?.state ?? '',
            country: record?.country ?? 'India',
            pincode: record?.pincode ?? '',
            bank_name: record?.bank_name ?? '',
            bank_account_no: record?.bank_account_no ?? '',
            bank_ifsc: record?.bank_ifsc ?? '',
            credit_days: record?.credit_days ?? 0,
            credit_limit: record?.credit_limit ?? 0,
            opening_balance: record?.opening_balance ?? 0,
            notes: record?.notes ?? '',
            status: record?.status ?? 'active',
        },
    });

    const [processing, setProcessing] = useState(false);

    function onSubmit(values) {
        setProcessing(true);
        const options = {
            preserveScroll: true,
            onError: (serverErrors) => {
                // Surface Laravel validation errors on the matching fields.
                Object.entries(serverErrors).forEach(([field, message]) =>
                    setError(field, { type: 'server', message }),
                );
            },
            onFinish: () => setProcessing(false),
        };

        if (isEdit) {
            router.put(`${base}/${record.id}`, values, options);
        } else {
            router.post(base, values, options);
        }
    }

    function cancel() {
        if (!isDirty) return router.get(base);
        Swal.fire({
            title: 'Discard changes?',
            text: 'Any unsaved changes will be lost.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Discard',
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
        }).then((result) => {
            if (result.isConfirmed) router.get(base);
        });
    }

    return (
        <>
            <Head title={isEdit ? `Edit ${record.name}` : 'New supplier'} />

            <form
                onSubmit={handleSubmit(onSubmit)}
                className="space-y-5 p-4 sm:p-6"
            >
                <PageHeader
                    title={isEdit ? 'Edit supplier' : 'New supplier'}
                    description="Enter supplier identity, statutory, banking and credit details."
                    actions={
                        <Link href={base}>
                            <Button type="button" variant="outline" size="sm">
                                <ArrowLeftIcon className="size-4" /> Back
                            </Button>
                        </Link>
                    }
                />

                <Section
                    title="Identity"
                    description="Basic supplier information."
                >
                    <Field label="Supplier code" required error={errors.supplier_code}>
                        <Input
                            {...register('supplier_code', {
                                required: 'Supplier code is required',
                            })}
                            placeholder="SUP-0001"
                        />
                    </Field>
                    <Field label="Name" required error={errors.name}>
                        <Input
                            {...register('name', { required: 'Name is required' })}
                            placeholder="Green Roots Nursery"
                        />
                    </Field>
                    <Field label="Email" error={errors.email}>
                        <Input type="email" {...register('email')} />
                    </Field>
                    <Field label="Phone" error={errors.phone}>
                        <Input {...register('phone')} />
                    </Field>
                    <Field label="Status" required error={errors.status}>
                        <select
                            {...register('status', { required: true })}
                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring"
                        >
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </Section>

                <Section
                    title="Statutory"
                    description="Stored encrypted at rest."
                >
                    <Field label="GST number" error={errors.gst_number}>
                        <Input {...register('gst_number')} placeholder="22AAAAA0000A1Z5" />
                    </Field>
                    <Field label="PAN number" error={errors.pan_number}>
                        <Input {...register('pan_number')} placeholder="AAAAA0000A" />
                    </Field>
                </Section>

                <Section title="Address">
                    <Field label="Address line 1" error={errors.address_line1}>
                        <Input {...register('address_line1')} />
                    </Field>
                    <Field label="Address line 2" error={errors.address_line2}>
                        <Input {...register('address_line2')} />
                    </Field>
                    <Field label="City" error={errors.city}>
                        <Input {...register('city')} />
                    </Field>
                    <Field label="State" error={errors.state}>
                        <Input {...register('state')} />
                    </Field>
                    <Field label="Country" error={errors.country}>
                        <Input {...register('country')} />
                    </Field>
                    <Field label="Pincode" error={errors.pincode}>
                        <Input {...register('pincode')} />
                    </Field>
                </Section>

                <Section
                    title="Banking"
                    description="Account number is stored encrypted."
                >
                    <Field label="Bank name" error={errors.bank_name}>
                        <Input {...register('bank_name')} />
                    </Field>
                    <Field label="Account number" error={errors.bank_account_no}>
                        <Input {...register('bank_account_no')} />
                    </Field>
                    <Field label="IFSC" error={errors.bank_ifsc}>
                        <Input {...register('bank_ifsc')} />
                    </Field>
                </Section>

                <Section
                    title="Commercials"
                    description="Credit terms and opening balance."
                >
                    <Field label="Credit days" error={errors.credit_days}>
                        <Input
                            type="number"
                            min="0"
                            {...register('credit_days', { valueAsNumber: true })}
                        />
                    </Field>
                    <Field label="Credit limit" error={errors.credit_limit}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            {...register('credit_limit', { valueAsNumber: true })}
                        />
                    </Field>
                    <Field label="Opening balance" error={errors.opening_balance}>
                        <Input
                            type="number"
                            step="0.01"
                            {...register('opening_balance', { valueAsNumber: true })}
                        />
                    </Field>
                </Section>

                <Section title="Notes">
                    <div className="sm:col-span-2">
                        <textarea
                            {...register('notes')}
                            rows={3}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring"
                            placeholder="Internal notes about this supplier…"
                        />
                    </div>
                </Section>

                {/* Sticky action bar */}
                <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-border bg-background/80 py-3 backdrop-blur">
                    <Button type="button" variant="outline" onClick={cancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        <CheckIcon className="size-4" />
                        {processing
                            ? 'Saving…'
                            : isEdit
                              ? 'Save changes'
                              : 'Create supplier'}
                    </Button>
                </div>
            </form>
        </>
    );
}

SupplierForm.layout = (props) => ({
    breadcrumbs: [
        { title: 'Suppliers', href: `/${props.team}/suppliers` },
        {
            title: props.supplier ? 'Edit' : 'New',
            href: '#',
        },
    ],
});
