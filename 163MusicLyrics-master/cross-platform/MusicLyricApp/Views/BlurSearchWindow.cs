using System.Collections.Generic;
using Avalonia.Controls;
using MusicLyricApp.Models;
using MusicLyricApp.ViewModels;

namespace MusicLyricApp.Views;

public class BlurSearchWindow : Window
{
    public BlurSearchWindow(List<SearchResultVo> searchResList)
    {
        Title = "搜索结果";
        Width = 1400;
        Height = 700;

        var viewModel = new BlurSearchViewModel(searchResList);
        
        DataContext = viewModel;
        Content = new BlurSearchView(viewModel);
        Icon = Constants.GetIcon("search-result");
        
        viewModel.LoadTypeAResults();
    }
}