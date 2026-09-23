import { useId, useMemo, useState } from 'react';
import { format } from 'date-fns';
import { CalendarDays, ChevronDown, Clock3 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import './StaffDatePicker.css';

const parseDateValue = (value) => {
  if (!value) return undefined;
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);
  if (!year || !month || !day) return undefined;
  const parsed = new Date(year, month - 1, day);
  return Number.isNaN(parsed.getTime()) ? undefined : parsed;
};

const formatDateValue = (date) => format(date, 'yyyy-MM-dd');

const clampDateTime = (date, time, min, max) => {
  const nextValue = `${formatDateValue(date)}T${time || '00:00'}`;
  const minimum = min?.slice(0, 16);
  const maximum = max?.slice(0, 16);

  if (minimum && nextValue < minimum) return minimum;
  if (maximum && nextValue > maximum) return maximum;
  return nextValue;
};

const getDisabledMatcher = (min, max) => {
  const minimum = min?.slice(0, 10);
  const maximum = max?.slice(0, 10);
  if (!minimum && !maximum) return undefined;

  return (date) => {
    const value = formatDateValue(date);
    return Boolean((minimum && value < minimum) || (maximum && value > maximum));
  };
};

const DateTrigger = ({ value, emptyLabel, invalid, className, includeTime, ...props }) => {
  const selectedDate = parseDateValue(value);
  const time = value?.split('T')[1]?.slice(0, 5);
  const displayTime = time
    ? new Date(`2000-01-01T${time}`).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
    : '';

  return (
    <Button
      {...props}
      type="button"
      variant="outline"
      data-empty={!selectedDate}
      data-invalid={invalid || undefined}
      className={cn('staff-date-picker-trigger', className)}
    >
      <CalendarDays className="staff-date-picker-icon" />
      <span className="staff-date-picker-value">
        {selectedDate ? format(selectedDate, 'MMM d, yyyy') : emptyLabel}
        {includeTime && displayTime && <small>{displayTime}</small>}
      </span>
      <ChevronDown className="staff-date-picker-chevron" />
    </Button>
  );
};

const StaffDatePicker = ({
  value,
  onChange,
  min,
  max,
  emptyLabel = 'Select date',
  ariaLabel = 'Select date',
  invalid = false,
  clearable = false,
  className,
}) => {
  const [open, setOpen] = useState(false);
  const selectedDate = parseDateValue(value);
  const disabled = useMemo(() => getDisabledMatcher(min, max), [min, max]);

  const handleSelect = (date) => {
    if (!date) return;
    onChange(formatDateValue(date));
    setOpen(false);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        aria-label={ariaLabel}
        render={
          <DateTrigger
            value={value}
            emptyLabel={emptyLabel}
            invalid={invalid}
            className={className}
          />
        }
      />
      <PopoverContent align="start" className="staff-date-picker-popover">
        <Calendar
          mode="single"
          selected={selectedDate}
          onSelect={handleSelect}
          disabled={disabled}
          defaultMonth={selectedDate}
          className="staff-date-picker-calendar"
        />
        {clearable && value && (
          <div className="staff-date-picker-actions">
            <button type="button" onClick={() => { onChange(''); setOpen(false); }}>
              Clear date
            </button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
};

const StaffDateTimePicker = ({
  value,
  onChange,
  min,
  max,
  emptyLabel = 'Select date and time',
  ariaLabel = 'Select date and time',
  invalid = false,
  className,
  collisionPadding,
  popoverClassName,
}) => {
  const [open, setOpen] = useState(false);
  const timeInputId = useId();
  const selectedDate = parseDateValue(value);
  const selectedTime = value?.split('T')[1]?.slice(0, 5) || '00:00';
  const disabled = useMemo(() => getDisabledMatcher(min, max), [min, max]);
  const selectedDayValue = selectedDate ? formatDateValue(selectedDate) : '';
  const minimumTime = min?.startsWith(`${selectedDayValue}T`) ? min.slice(11, 16) : undefined;
  const maximumTime = max?.startsWith(`${selectedDayValue}T`) ? max.slice(11, 16) : undefined;

  const handleDateSelect = (date) => {
    if (!date) return;
    onChange(clampDateTime(date, selectedTime, min, max));
  };

  const handleTimeChange = (event) => {
    const date = selectedDate || parseDateValue(min) || new Date();
    onChange(clampDateTime(date, event.target.value, min, max));
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        aria-label={ariaLabel}
        render={
          <DateTrigger
            value={value}
            emptyLabel={emptyLabel}
            invalid={invalid}
            className={className}
            includeTime
          />
        }
      />
      <PopoverContent align="start" collisionPadding={collisionPadding} className={cn('staff-date-picker-popover staff-date-time-picker-popover', popoverClassName)}>
        <Calendar
          mode="single"
          selected={selectedDate}
          onSelect={handleDateSelect}
          disabled={disabled}
          defaultMonth={selectedDate}
          className="staff-date-picker-calendar"
        />
        <div className="staff-date-time-row">
          <label htmlFor={timeInputId}>
            <Clock3 />
            Time
          </label>
          <input
            id={timeInputId}
            type="time"
            value={selectedTime}
            min={minimumTime}
            max={maximumTime}
            onChange={handleTimeChange}
          />
        </div>
        <div className="staff-date-picker-actions staff-date-time-actions">
          <button type="button" onClick={() => setOpen(false)}>Done</button>
        </div>
      </PopoverContent>
    </Popover>
  );
};

export { StaffDatePicker, StaffDateTimePicker };
