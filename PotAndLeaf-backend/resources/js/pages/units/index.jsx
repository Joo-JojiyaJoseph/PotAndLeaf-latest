import LookupResource from '@/components/nursery/LookupResource';

const STATUS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

export default function UnitsIndex(props) {
    const config = {
        title: 'Product units',
        description: 'Units of measure such as piece, kilogram or litre.',
        singular: 'unit',
        slug: 'units',
        columns: [
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'short_name', label: 'Short' },
        ],
        fields: [
            { name: 'code', label: 'Code', required: true, placeholder: 'UOM-01' },
            { name: 'name', label: 'Name', required: true, placeholder: 'Kilogram' },
            { name: 'short_name', label: 'Short name', placeholder: 'kg' },
            { name: 'description', label: 'Description', type: 'textarea', colSpan: 2 },
            { name: 'status', label: 'Status', type: 'select', required: true, options: STATUS },
        ],
    };
    return <LookupResource config={config} {...props} />;
}

UnitsIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Units', href: `/${props.team}/units` }],
});
