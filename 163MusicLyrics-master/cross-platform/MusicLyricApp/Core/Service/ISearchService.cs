using System.Collections.Generic;
using System.Threading.Tasks;
using MusicLyricApp.Core.Service.Music;
using MusicLyricApp.Models;
using MusicLyricApp.ViewModels;
using SearchParamViewModel = MusicLyricApp.ViewModels.SearchParamViewModel;

namespace MusicLyricApp.Core.Service;

public interface ISearchService
{
    IMusicApi GetMusicApi(SearchSourceEnum searchSource);
    
    void InitSongIds(SearchParamViewModel searchParam, SettingBean settingBean);

    Dictionary<string, ResultVo<SaveVo>> SearchSongs(List<InputSongId> inputSongIds, SettingBean settingBean);

    Task<bool> RenderSearchResult(
        SearchParamViewModel searchParam, 
        SearchResultViewModel searchResult, 
        SettingBean settingBean, 
        Dictionary<string, ResultVo<SaveVo>> resDict);

    List<SearchResultVo> BlurSearch(SearchParamViewModel searchParam, SettingBean settingBean);
}