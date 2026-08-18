import LookupResource from '@/components/nursery/LookupResource';

const STATUS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

export default function CategoriesIndex(props) {
    const config = {
        title: 'Product categories',
        description: 'Group products into categories for reporting and filtering.',
        singular: 'category',
        slug: 'categories',
        columns: [
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
        ],
        fields: [
            { name: 'code', label: 'Code', required: true, placeholder: 'CAT-01' },
            { name: 'name', label: 'Name', required: true, placeholder: 'Indoor Plants' },
            { name: 'description', label: 'Description', type: 'textarea', colSpan: 2 },
            { name: 'status', label: 'Status', type: 'select', required: true, options: STATUS },
        ],
    };
    return <LookupResource config={config} {...props} />;
}

CategoriesIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Categories', href: `/${props.team}/categories` }],
});
