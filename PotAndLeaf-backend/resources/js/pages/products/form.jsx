import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFieldArray, useForm } from 'react-hook-form';
import {
    ArrowLeftIcon,
    CheckIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/nursery/PageHeader';

function Field({ label, error, required, colSpan, children }) {
    return (
        <div className={`space-y-1.5 ${colSpan === 2 ? 'sm:col-span-2' : ''}`}>
            <Label>
                {label}
                {required && <span className="text-danger"> *</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-danger">{error.message}</p>}
        </div>
    );
}

function Section({ title, description, children }) {
    return (
        <section className="rounded-lg border border-border bg-card p-5">
            <div className="mb-4">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && (
                    <p className="text-xs text-muted-foreground">{description}</p>
                )}
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
        </section>
    );
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring';

export default function ProductForm({
    team,
    product,
    statusOptions = [],
    categories = [],
    brands = [],
    units = [],
    suppliers = [],
}) {
    const record = product?.data ?? product ?? null;
    const isEdit = Boolean(record);
    const base = `/${team}/products`;

    const {
        register,
        control,
        handleSubmit,
        setError,
        formState: { errors, isDirty },
    } = useForm({
        defaultValues: {
            sku: record?.sku ?? '',
            name: record?.name ?? '',
            barcode: record?.barcode ?? '',
            hsn_code: record?.hsn_code ?? '',
            description: record?.description ?? '',
            category_id: record?.category_id ?? '',
            brand_id: record?.brand_id ?? '',
            unit_id: record?.unit_id ?? '',
            gst_rate: record?.gst_rate ?? 0,
            mrp: record?.mrp ?? 0,
            cost_price: record?.cost_price ?? 0,
            dealer_price: record?.dealer_price ?? 0,
            wholesale_price: record?.wholesale_price ?? 0,
            retail_price: record?.retail_price ?? 0,
            reorder_level: record?.reorder_level ?? 0,
            opening_stock: record?.opening_stock ?? 0,
            status: record?.status ?? 'active',
            suppliers:
                record?.suppliers?.length > 0
                    ? record.suppliers.map((s) => ({
                          supplier_id: s.supplier_id,
                          supplier_price: s.supplier_price,
                          is_primary: s.is_primary,
                      }))
                    : [],
            imageRows: (record?.images ?? []).map((url) => ({ url })),
        },
    });

    const supplierRows = useFieldArray({ control, name: 'suppliers' });
    const imageRows = useFieldArray({ control, name: 'imageRows' });

    const [processing, setProcessing] = useState(false);

    function onSubmit(values) {
        setProcessing(true);
        const payload = {
            ...values,
            images: (values.imageRows ?? [])
                .map((r) => r.url)
                .filter(Boolean),
        };
        delete payload.imageRows;

        const options = {
            preserveScroll: true,
            onError: (serverErrors) =>
                Object.entries(serverErrors).forEach(([field, message]) =>
                    setError(field, { type: 'server', message }),
                ),
            onFinish: () => setProcessing(false),
        };

        if (isEdit) {
            router.put(`${base}/${record.id}`, payload, options);
        } else {
            router.post(base, payload, options);
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
            <Head title={isEdit ? `Edit ${record.name}` : 'New product'} />

            <form onSubmit={handleSubmit(onSubmit)} className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title={isEdit ? 'Edit product' : 'New product'}
                    description="Identity, classification, pricing, stock and supplier sourcing."
                    actions={
                        <Link href={base}>
                            <Button type="button" variant="outline" size="sm">
                                <ArrowLeftIcon className="size-4" /> Back
                            </Button>
                        </Link>
                    }
                />

                <Section title="Identity">
                    <Field label="SKU" required error={errors.sku}>
                        <Input
                            {...register('sku', { required: 'SKU is required' })}
                            placeholder="PRD-0001"
                        />
                    </Field>
                    <Field label="Name" required error={errors.name}>
                        <Input {...register('name', { required: 'Name is required' })} />
                    </Field>
                    <Field label="Barcode" error={errors.barcode}>
                        <Input {...register('barcode')} />
                    </Field>
                    <Field label="HSN code" error={errors.hsn_code}>
                        <Input {...register('hsn_code')} />
                    </Field>
                    <Field label="Status" required error={errors.status}>
                        <select {...register('status')} className={selectClass}>
                            {statusOptions.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </Section>

                <Section title="Classification">
                    <Field label="Category" error={errors.category_id}>
                        <select {...register('category_id')} className={selectClass}>
                            <option value="">Select category…</option>
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Brand" error={errors.brand_id}>
                        <select {...register('brand_id')} className={selectClass}>
                            <option value="">Select brand…</option>
                            {brands.map((b) => (
                                <option key={b.value} value={b.value}>
                                    {b.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Unit" error={errors.unit_id}>
                        <select {...register('unit_id')} className={selectClass}>
                            <option value="">Select unit…</option>
                            {units.map((u) => (
                                <option key={u.value} value={u.value}>
                                    {u.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="GST rate (%)" error={errors.gst_rate}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            {...register('gst_rate', { valueAsNumber: true })}
                        />
                    </Field>
                </Section>

                <Section title="Pricing">
                    {[
                        ['mrp', 'MRP'],
                        ['cost_price', 'Cost price'],
                        ['dealer_price', 'Dealer price'],
                        ['wholesale_price', 'Wholesale price'],
                        ['retail_price', 'Retail price'],
                    ].map(([name, label]) => (
                        <Field key={name} label={label} error={errors[name]}>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                {...register(name, { valueAsNumber: true })}
                            />
                        </Field>
                    ))}
                </Section>

                <Section
                    title="Stock"
                    description="Opening stock seeds the current stock; movements are handled by inventory."
                >
                    <Field label="Reorder level" error={errors.reorder_level}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            {...register('reorder_level', { valueAsNumber: true })}
                        />
                    </Field>
                    <Field
                        label="Opening stock"
                        error={errors.opening_stock}
                    >
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            disabled={isEdit}
                            {...register('opening_stock', { valueAsNumber: true })}
                        />
                    </Field>
                </Section>

                {/* Suppliers (repeatable) */}
                <section className="rounded-lg border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">Suppliers</h2>
                            <p className="text-xs text-muted-foreground">
                                Source this product from one or more suppliers.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                supplierRows.append({
                                    supplier_id: '',
                                    supplier_price: 0,
                                    is_primary: supplierRows.fields.length === 0,
                                })
                            }
                        >
                            <PlusIcon className="size-4" /> Add supplier
                        </Button>
                    </div>

                    {supplierRows.fields.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No suppliers linked yet.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {supplierRows.fields.map((field, index) => (
                                <div
                                    key={field.id}
                                    className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_140px_auto_auto] sm:items-center"
                                >
                                    <select
                                        {...register(`suppliers.${index}.supplier_id`, {
                                            required: true,
                                        })}
                                        className={selectClass}
                                    >
                                        <option value="">Select supplier…</option>
                                        {suppliers.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="Price"
                                        {...register(`suppliers.${index}.supplier_price`, {
                                            valueAsNumber: true,
                                        })}
                                    />
                                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            {...register(`suppliers.${index}.is_primary`)}
                                            className="size-4 rounded border-input text-primary focus:ring-ring"
                                        />
                                        Primary
                                    </label>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove supplier"
                                        onClick={() => supplierRows.remove(index)}
                                    >
                                        <TrashIcon className="size-4 text-danger" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {/* Media (image paths/URLs; real upload is a follow-up) */}
                <section className="rounded-lg border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">Images</h2>
                            <p className="text-xs text-muted-foreground">
                                Add image URLs or stored paths. The first is the primary image.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => imageRows.append({ url: '' })}
                        >
                            <PlusIcon className="size-4" /> Add image
                        </Button>
                    </div>

                    {imageRows.fields.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No images added.</p>
                    ) : (
                        <div className="space-y-2">
                            {imageRows.fields.map((field, index) => (
                                <div key={field.id} className="flex items-center gap-2">
                                    <Input
                                        placeholder="https://… or storage path"
                                        {...register(`imageRows.${index}.url`)}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove image"
                                        onClick={() => imageRows.remove(index)}
                                    >
                                        <TrashIcon className="size-4 text-danger" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <Section title="Description">
                    <div className="sm:col-span-2">
                        <textarea
                            {...register('description')}
                            rows={3}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring"
                            placeholder="Product description…"
                        />
                    </div>
                </Section>

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
                              : 'Create product'}
                    </Button>
                </div>
            </form>
        </>
    );
}

ProductForm.layout = (props) => ({
    breadcrumbs: [
        { title: 'Products', href: `/${props.team}/products` },
        { title: props.product ? 'Edit' : 'New', href: '#' },
    ],
});
