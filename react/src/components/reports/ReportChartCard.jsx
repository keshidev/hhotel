import React, { useMemo } from 'react';
import { Chart } from '@highcharts/react';
import 'highcharts/es-modules/masters/modules/accessibility.src.js';
import './ReportChartCard.css';

const BASE_COLORS = ['#1a4bcc', '#10b981', '#f59e0b', '#ef4444', '#7c3aed', '#0891b2', '#64748b'];

export const formatChartDate = (value) => {
  if (!value) return '';
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
};

export const createReportChartOptions = (overrides = {}) => {
  const reducedMotion = typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

  const base = {
    chart: {
      backgroundColor: 'transparent',
      height: 340,
      spacing: [12, 8, 8, 8],
      animation: reducedMotion ? false : { duration: 450 },
      style: { fontFamily: 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' },
    },
    title: { text: null },
    subtitle: { text: null },
    colors: BASE_COLORS,
    credits: {
      enabled: true,
      style: { color: '#94a3b8', fontSize: '9px' },
    },
    exporting: { enabled: false },
    legend: {
      align: 'left',
      verticalAlign: 'top',
      itemStyle: { color: '#334155', fontSize: '12px', fontWeight: '600' },
      itemHoverStyle: { color: '#0f172a' },
      symbolRadius: 4,
    },
    xAxis: {
      lineColor: '#e2e8f0',
      tickColor: '#e2e8f0',
      labels: { style: { color: '#64748b', fontSize: '11px' } },
      title: { style: { color: '#475569', fontSize: '11px', fontWeight: '600' } },
    },
    yAxis: {
      gridLineColor: '#e8edf5',
      lineWidth: 0,
      title: { text: null },
      labels: { style: { color: '#64748b', fontSize: '11px' } },
    },
    tooltip: {
      shared: true,
      useHTML: true,
      backgroundColor: '#0f172a',
      borderColor: '#0f172a',
      borderRadius: 10,
      shadow: false,
      style: { color: '#ffffff', fontSize: '12px' },
    },
    plotOptions: {
      series: {
        animation: reducedMotion ? false : { duration: 500 },
        borderWidth: 0,
        states: { inactive: { opacity: 0.35 } },
      },
      column: { borderRadius: 4, groupPadding: 0.14, pointPadding: 0.05 },
      bar: { borderRadius: 4, groupPadding: 0.14, pointPadding: 0.05 },
    },
    accessibility: { enabled: true },
  };

  return {
    ...base,
    ...overrides,
    chart: { ...base.chart, ...overrides.chart },
    title: { ...base.title, ...overrides.title },
    subtitle: { ...base.subtitle, ...overrides.subtitle },
    credits: { ...base.credits, ...overrides.credits },
    exporting: { ...base.exporting, ...overrides.exporting },
    legend: { ...base.legend, ...overrides.legend },
    xAxis: { ...base.xAxis, ...overrides.xAxis },
    yAxis: { ...base.yAxis, ...overrides.yAxis },
    tooltip: { ...base.tooltip, ...overrides.tooltip },
    plotOptions: {
      ...base.plotOptions,
      ...overrides.plotOptions,
      series: { ...base.plotOptions.series, ...overrides.plotOptions?.series },
      column: { ...base.plotOptions.column, ...overrides.plotOptions?.column },
      bar: { ...base.plotOptions.bar, ...overrides.plotOptions?.bar },
    },
  };
};

const ReportChartCard = ({ title, subtitle, badge, actions, options, empty = false, emptyMessage = 'No chart data for this period.' }) => {
  const stableOptions = useMemo(() => options, [options]);

  return (
    <section className="report-chart-card" aria-label={title}>
      <div className="report-chart-card__header">
        <div>
          <h3>{title}</h3>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {actions ?? (badge && <span className="report-chart-card__badge">{badge}</span>)}
      </div>
      {empty ? (
        <div className="report-chart-card__empty">{emptyMessage}</div>
      ) : (
        <div className="report-chart-card__canvas">
          <Chart options={stableOptions} containerProps={{ style: { width: '100%', height: '100%' } }} />
        </div>
      )}
    </section>
  );
};

export default ReportChartCard;
