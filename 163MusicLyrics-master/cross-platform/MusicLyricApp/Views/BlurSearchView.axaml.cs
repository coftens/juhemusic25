using Avalonia.Controls;
using MusicLyricApp.ViewModels;

namespace MusicLyricApp.Views;

public partial class BlurSearchView : UserControl
{
    public BlurSearchView(BlurSearchViewModel vm)
    {
        InitializeComponent();

        // 设置动态列
        vm.ColumnsChanged += gridTemplate =>
        {
            BlurDataGrid.Columns.Clear();
            foreach (var column in gridTemplate)
            {
                column.CanUserSort = false; // 不启用排序
                BlurDataGrid.Columns.Add(column);
            }
        };

        BlurDataGrid.SelectionChanged += OnSelectionChanged;
    }

    private void OnSelectionChanged(object? sender, SelectionChangedEventArgs e)
    {
        if (DataContext is BlurSearchViewModel vm)
        {
            vm.SelectedItems = BlurDataGrid.SelectedItems;
        }
    }
}