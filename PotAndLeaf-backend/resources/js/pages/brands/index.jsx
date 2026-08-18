import LookupResource from '@/components/nursery/LookupResource';

const STATUS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

export default function BrandsIndex(props) {
    const config = {
        title: 'Product brands',
        description: 'Manufacturer or brand names used across products.',
        singular: 'brand',
        slug: 'brands',
        columns: [
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
        ],
        fields: [
            { name: 'code', label: 'Code', required: true, placeholder: 'BRD-01' },
            { name: 'name', label: 'Name', required: true },
            { name: 'description', label: 'Description', type: 'textarea', colSpan: 2 },
            { name: 'status', label: 'Status', type: 'select', required: true, options: STATUS },
        ],
    };
    return <LookupResource config={config} {...props} />;
}

BrandsIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Brands', href: `/${props.team}/brands` }],
});
