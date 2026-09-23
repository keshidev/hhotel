import './TableActionButton.css';

export default function TableActionButton({
  children,
  label,
  tone = 'primary',
  iconOnly = false,
  className = '',
  type = 'button',
  ...props
}) {
  return (
    <button
      type={type}
      className={`hhotel-table-action hhotel-table-action--${tone} ${iconOnly ? 'hhotel-table-action--icon' : ''} ${className}`.trim()}
      aria-label={props['aria-label'] || (iconOnly ? label : undefined)}
      title={props.title || label}
      {...props}
    >
      {children}
    </button>
  );
}
