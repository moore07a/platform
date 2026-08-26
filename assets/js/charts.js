/**
 * Chart.js Integration for Farm Management System
 */

// Chart instances storage
let chartInstances = {};

// Network timeout for chart data fetches (milliseconds)
const CHART_FETCH_TIMEOUT = 10000;

function withTimeout(promise, timeoutMs) {
    const controller = new AbortController();
    let timeoutId;
    const timeoutPromise = new Promise((_, reject) => {
        timeoutId = setTimeout(() => {
            controller.abort();
            reject(new Error('Request timed out'));
        }, timeoutMs);
    });

    return Promise.race([
        promise(controller),
        timeoutPromise
    ]).finally(() => clearTimeout(timeoutId));
}

async function fetchChartJson(url) {
    return withTimeout(async (controller) => {
        const response = await fetch(url, { signal: controller.signal });
        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        try {
            return await response.json();
        } catch (error) {
            throw new Error('Received invalid JSON from server');
        }
    }, CHART_FETCH_TIMEOUT);
}

function chartingUnavailable() {
    return typeof Chart === 'undefined' || Chart.__fallbackStub === true;
}

function renderChartUnavailableNotice(targetId, messageText) {
    const target = document.getElementById(targetId);
    if (!target || target.dataset.chartFallbackShown) return;
    target.dataset.chartFallbackShown = 'true';
    const message = document.createElement('div');
    message.className = 'alert alert-warning mt-2';
    message.textContent = messageText || 'Charts are temporarily unavailable because Chart.js did not load.';
    target.replaceWith(message);
}

/**
 * Initialize all charts on page
 */
function initializeCharts() {
    if (chartingUnavailable()) {
        ['profitChart', 'salesChart', 'expenseChart', 'stockChart', 'productionChart']
            .forEach((id) => renderChartUnavailableNotice(id));
        return;
    }
    // Profit/Loss Chart
    const profitChartCanvas = document.getElementById('profitChart');
    if (profitChartCanvas) {
        createProfitLossChart();
    }
    
    // Sales Chart
    const salesChartCanvas = document.getElementById('salesChart');
    if (salesChartCanvas) {
        createSalesChart();
    }
    
    // Expense Breakdown Chart
    const expenseChartCanvas = document.getElementById('expenseChart');
    if (expenseChartCanvas) {
        createExpenseBreakdownChart();
    }
    
    // Stock Level Chart
    const stockChartCanvas = document.getElementById('stockChart');
    if (stockChartCanvas) {
        createStockLevelChart();
    }
    
    // Production Chart
    const productionChartCanvas = document.getElementById('productionChart');
    if (productionChartCanvas) {
        createProductionChart();
    }
}

async function loadChartData(chartType, period = 'month', targetId) {
    try {
        const url = `api/get_chart_data.php?type=${chartType}&period=${period}`;
        return await fetchChartJson(url);
    } catch (error) {
        console.error('Error loading chart data:', error);
        if (targetId) {
            renderChartUnavailableNotice(targetId, `Chart data unavailable. ${error.message}`);
        }
        return null;
    }
}

/**
 * Create Profit/Loss Chart
 */
async function createProfitLossChart() {
    try {
        const data = await loadChartData('profit_loss', 'year', 'profitChart');
        if (!data) return;
        
        const ctx = document.getElementById('profitChart').getContext('2d');
        
        chartInstances.profitChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Profit/Loss (₦)',
                    data: data.values || [],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₦' + context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating profit/loss chart:', error);
    }
}

/**
 * Create Sales Chart
 */
async function createSalesChart() {
    try {
        const data = await loadChartData('sales', 'month', 'salesChart');
        if (!data) return;
        
        const ctx = document.getElementById('salesChart').getContext('2d');
        
        chartInstances.salesChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Poultry Sales (₦)',
                    data: data.poultry || [],
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }, {
                    label: 'Ruminant Sales (₦)',
                    data: data.ruminant || [],
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₦' + context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating sales chart:', error);
    }
}

/**
 * Create Expense Breakdown Chart
 */
async function createExpenseBreakdownChart() {
    try {
        const data = await loadChartData('expenses', 'month', 'expenseChart');
        if (!data) return;
        
        const ctx = document.getElementById('expenseChart').getContext('2d');
        
        chartInstances.expenseChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels || [],
                datasets: [{
                    data: data.values || [],
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)',
                        'rgba(255, 159, 64, 0.7)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₦' + context.parsed.toLocaleString();
                                label += ' (' + context.parsed.toFixed(1) + '%)';
                                return label;
                            }
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating expense chart:', error);
    }
}

/**
 * Create Stock Level Chart
 */
async function createStockLevelChart() {
    try {
        const data = await loadChartData('stock', 'week', 'stockChart');
        if (!data) return;
        
        const ctx = document.getElementById('stockChart').getContext('2d');
        
        chartInstances.stockChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: data.datasets || []
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Quantity'
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                hover: {
                    mode: 'nearest',
                    intersect: true
                }
            }
        });
    } catch (error) {
        console.error('Error creating stock chart:', error);
    }
}

/**
 * Create Production Chart
 */
async function createProductionChart() {
    try {
        const data = await loadChartData('production', 'month', 'productionChart');
        if (!data) return;
        
        const ctx = document.getElementById('productionChart').getContext('2d');
        
        chartInstances.productionChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{
                    label: 'Egg Production',
                    data: data.eggs || [],
                    backgroundColor: 'rgba(255, 206, 86, 0.7)',
                    borderColor: 'rgba(255, 206, 86, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Laying Rate (%)',
                    data: data.rates || [],
                    type: 'line',
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Eggs'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Rate (%)'
                        },
                        min: 0,
                        max: 100,
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += context.parsed.y + ' eggs';
                                } else {
                                    label += context.parsed.y + '%';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating production chart:', error);
    }
}

/**
 * Update chart with new data
 */
function updateChart(chartId, newData) {
    const chart = chartInstances[chartId];
    if (chart) {
        chart.data = newData;
        chart.update();
    }
}

/**
 * Refresh all charts
 */
function refreshCharts() {
    Object.keys(chartInstances).forEach(chartId => {
        const chart = chartInstances[chartId];
        if (chart) {
            chart.destroy();
        }
    });
    
    chartInstances = {};
    initializeCharts();
}

/**
 * Export chart as image
 */
function exportChartAsImage(chartId, filename) {
    const chart = chartInstances[chartId];
    if (!chart) return;
    
    const image = chart.toBase64Image();
    const link = document.createElement('a');
    link.href = image;
    link.download = filename || `${chartId}.png`;
    link.click();
}

// Initialize charts when DOM is loaded
document.addEventListener('DOMContentLoaded', initializeCharts);